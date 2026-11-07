<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Les fournisseurs d'IA de la page "الذكاء الاصطناعي" : Claude (Anthropic), ChatGPT (OpenAI), Gemini (Google).
 * Tester un lien = lister les modeles du compte : gratuit chez les trois, et la liste sert au choix du modele.
 * La cle passe toujours dans un en-tete, jamais dans l'URL (elle finirait dans les journaux).
 */
final class AiProviders
{
    /** name / company : noms de marque, les memes dans toutes les langues. keys_url : ou le compte cree sa cle. */
    public const PROVIDERS = [
        'claude' => [
            'name' => 'Claude',
            'company' => 'Anthropic',
            'keys_url' => 'https://console.anthropic.com/settings/keys',
            'models_url' => 'https://api.anthropic.com/v1/models?limit=1000',
            'placeholder' => 'sk-ant-…',
        ],
        'openai' => [
            'name' => 'ChatGPT',
            'company' => 'OpenAI',
            'keys_url' => 'https://platform.openai.com/api-keys',
            'models_url' => 'https://api.openai.com/v1/models',
            'placeholder' => 'sk-…',
        ],
        'gemini' => [
            'name' => 'Gemini',
            'company' => 'Google',
            'keys_url' => 'https://aistudio.google.com/app/apikey',
            'models_url' => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
            'placeholder' => 'AIza…',
        ],
    ];

    /** Pas plus de modeles gardes par fournisseur (la liste d'OpenAI compte des centaines d'instantanes). */
    private const MAX_MODELS = 150;

    public static function name(string $provider): string
    {
        return self::PROVIDERS[$provider]['name'];
    }

    /**
     * Les modeles de texte que la cle peut utiliser, le plus recent d'abord.
     *
     * @return list<string>
     *
     * @throws AiProviderError
     */
    public static function models(string $provider, string $key): array
    {
        try {
            $res = Http::withHeaders(self::headers($provider, $key))->acceptJson()
                ->connectTimeout(10)->timeout(20)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get(self::PROVIDERS[$provider]['models_url']);
        } catch (ConnectionException $e) {
            throw new AiProviderError('unreachable', self::scrub($e->getMessage(), $key));
        }

        if (! $res->successful()) {
            throw new AiProviderError(self::reason($provider, $res), self::scrub('HTTP '.$res->status().': '.self::message($res), $key));
        }

        return array_slice(array_values(match ($provider) {
            'claude' => self::claude($res->json('data') ?? []),
            'openai' => self::openai($res->json('data') ?? []),
            'gemini' => self::gemini($res->json('models') ?? []),
        }), 0, self::MAX_MODELS);
    }

    /** Une reponse peut prendre du temps (les modeles reflechissent avant) : sous le verrou d'un lot (MergeScanner). */
    public const ASK_TIMEOUT = 180;

    /**
     * Une question dont la reponse est un objet JSON de la forme $schema (JSON Schema, tout "required",
     * additionalProperties false : ce qu'OpenAI exige en mode strict). Claude a l'outil "answer" sans y etre force
     * (Opus 5.5, Sonnet 5.5, Fable 5.1 refusent tool_choice "tool" par un 400) : sa reponse est l'outil, ou le JSON
     * de son texte. OpenAI et Gemini en mode JSON ; le message decrit aussi la forme attendue, pour les trois.
     * $maxTokens = la reponse ; la limite envoyee est le double, car les modeles qui raisonnent y comptent leur reflexion.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     *
     * @throws AiProviderError
     */
    public static function ask(string $provider, string $key, string $model, string $system, string $prompt, array $schema, int $maxTokens = 8000): array
    {
        [$url, $body] = match ($provider) {
            'claude' => ['https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens * 2,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'tools' => [['name' => 'answer', 'description' => 'Give the answer. Always answer by calling this tool once.', 'input_schema' => $schema]],
                'tool_choice' => ['type' => 'auto'],
            ]],
            'openai' => ['https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'answer', 'strict' => true, 'schema' => $schema]],
                'max_completion_tokens' => $maxTokens * 2,
            ]],
            'gemini' => ['https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'maxOutputTokens' => $maxTokens * 2],
            ]],
        };

        $res = self::post($provider, $key, $url, $body);

        $answer = match ($provider) {
            'claude' => collect($res->json('content') ?? [])->firstWhere('type', 'tool_use')['input']
                ?? self::decode(collect($res->json('content') ?? [])->where('type', 'text')->pluck('text')->implode('')),
            'openai' => self::decode((string) $res->json('choices.0.message.content')),
            'gemini' => self::decode(collect($res->json('candidates.0.content.parts') ?? [])->pluck('text')->implode('')),
        };
        if (! is_array($answer)) {
            // pourquoi elle s'est arretee (refusal, max_tokens, MAX_TOKENS, SAFETY...) d'abord : c'est ce qui aide a comprendre
            $stop = $res->json('stop_reason') ?? $res->json('choices.0.finish_reason') ?? $res->json('candidates.0.finishReason');
            throw new AiProviderError('bad_answer', self::scrub((is_string($stop) ? $stop.': ' : '').mb_substr($res->body(), 0, 200), $key));
        }

        return $answer;
    }

    /**
     * Une conversation en texte ("المطابقة", ReconciliationChat) : $messages alterne user / assistant et finit par user.
     * $system porte le rapport de la page, le meme d'une question a l'autre : chez Claude il est mis en cache
     * (cache_control), les questions suivantes le relisent pour moins cher. Ni "thinking" ni "temperature" : le modele
     * choisi sur la page "الذكاء الاصطناعي" garde ses reglages par defaut, et la limite est doublee pour sa reflexion.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, truncated: bool}
     *
     * @throws AiProviderError
     */
    public static function chat(string $provider, string $key, string $model, string $system, array $messages, int $maxTokens = 6000): array
    {
        [$url, $body] = match ($provider) {
            'claude' => ['https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => $maxTokens * 2,
                'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
                'messages' => $messages,
            ]],
            'openai' => ['https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $system], ...$messages],
                'max_completion_tokens' => $maxTokens * 2,
            ]],
            'gemini' => ['https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => array_map(fn (array $m) => ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $m['content']]]], $messages),
                'generationConfig' => ['maxOutputTokens' => $maxTokens * 2],
            ]],
        };
        $res = self::post($provider, $key, $url, $body);

        [$text, $stop] = match ($provider) {
            'claude' => [collect($res->json('content') ?? [])->where('type', 'text')->pluck('text')->implode(''), $res->json('stop_reason')],
            'openai' => [(string) $res->json('choices.0.message.content'), $res->json('choices.0.finish_reason')],
            'gemini' => [collect($res->json('candidates.0.content.parts') ?? [])->pluck('text')->implode(''), $res->json('candidates.0.finishReason')],
        };
        // refus de securite : Claude (stop_reason "refusal"), OpenAI (content_filter), Gemini (SAFETY...)
        if (in_array($stop, ['refusal', 'content_filter', 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true)) {
            throw new AiProviderError('refused', self::scrub((string) $stop, $key));
        }
        if (trim($text) === '') {
            throw new AiProviderError('bad_answer', self::scrub((is_string($stop) ? $stop.': ' : '').mb_substr($res->body(), 0, 200), $key));
        }

        return ['text' => trim($text), 'truncated' => in_array($stop, ['max_tokens', 'length', 'MAX_TOKENS'], true)];
    }

    /**
     * Un appel au fournisseur ; une erreur devient AiProviderError (reason = cle "ai.errors.*").
     * On ne refait que les connexions ratees : une reponse trop lente refaite trois fois depasserait le verrou d'un lot,
     * et une question refaite serait payee deux fois.
     */
    private static function post(string $provider, string $key, string $url, array $body): Response
    {
        try {
            $res = Http::withHeaders(self::headers($provider, $key))->acceptJson()
                ->connectTimeout(10)->timeout(self::ASK_TIMEOUT)
                ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException && ! str_contains($e->getMessage(), 'Operation timed out'), throw: false)
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new AiProviderError('unreachable', self::scrub($e->getMessage(), $key));
        }
        if (! $res->successful()) {
            throw new AiProviderError(self::reason($provider, $res), self::scrub('HTTP '.$res->status().': '.self::message($res), $key));
        }

        return $res;
    }

    /** Le JSON d'une reponse, meme entoure de ```json ... ``` ou d'une phrase. */
    private static function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($data) ? $data : null;
    }

    /** @return array<string, string> */
    private static function headers(string $provider, string $key): array
    {
        return match ($provider) {
            'claude' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
            'openai' => ['Authorization' => 'Bearer '.$key],
            'gemini' => ['x-goog-api-key' => $key],
        };
    }

    private static function reason(string $provider, Response $res): string
    {
        // Gemini refuse une mauvaise cle avec un 400 (API_KEY_INVALID), les autres avec 401
        if ($provider === 'gemini' && $res->status() === 400 && str_contains($res->body(), 'API_KEY_INVALID')) {
            return 'auth';
        }

        return match (true) {
            in_array($res->status(), [401, 403], true) => 'auth',
            $res->status() === 429 => 'quota',
            $res->status() >= 500 => 'down',
            default => 'other',
        };
    }

    private static function message(Response $res): string
    {
        $message = $res->json('error.message');

        return mb_substr(is_string($message) ? $message : trim(strip_tags($res->body())), 0, 200);
    }

    /** OpenAI ecrit une partie de la cle dans ses erreurs : jamais la cle dans ce qu'on garde. */
    private static function scrub(string $text, string $key): string
    {
        return mb_substr(str_replace($key, '…', $text), 0, 255);
    }

    /** Anthropic les donne deja du plus recent au plus ancien. */
    private static function claude(array $data): array
    {
        return collect($data)->pluck('id')->filter(fn ($id) => is_string($id))->all();
    }

    /** Toute la liste d'OpenAI : on garde les modeles de conversation, sans l'audio, l'image, l'embedding... */
    private static function openai(array $data): array
    {
        return collect($data)->sortByDesc('created')->pluck('id')
            ->filter(fn ($id) => is_string($id) && preg_match('/^(gpt-|chatgpt-|o\d)/', $id)
                && ! preg_match('/audio|realtime|transcribe|tts|image|search|embedding|instruct|moderation/', $id))
            ->all();
    }

    /** "models/gemini-…" qui savent generer du texte ; les versions stables avant les "preview" / "exp". */
    private static function gemini(array $models): array
    {
        return collect($models)
            ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true) && str_starts_with((string) ($m['name'] ?? ''), 'models/gemini'))
            ->map(fn ($m) => substr($m['name'], strlen('models/')))
            ->sort(fn (string $a, string $b) => [preg_match('/preview|exp/', $a), $b] <=> [preg_match('/preview|exp/', $b), $a])
            ->all();
    }
}
