// GescomAgent-Setup.exe : fenetre d'installation de GescomAgent sur le serveur Gescomm d'une branche.
// L'exe ne contient pas l'agent : il copie l'agent.ps1 place a cote de lui (zip "Telecharger l'agent" du tableau de bord),
// donc une nouvelle version d'agent.ps1 ne demande pas de recompiler. La logique est dans Installer.cs.
// C# 5 / .NET Framework 4 : compile par agent/setup/build.ps1 avec le csc.exe fourni avec Windows.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Reflection;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Web.Script.Serialization;
using System.Windows.Forms;

[assembly: AssemblyTitle("GescomAgent Setup")]
[assembly: AssemblyProduct("GescomAgent")]
[assembly: AssemblyVersion("1.0.0.0")]
[assembly: AssemblyFileVersion("1.0.0.0")]
// comportement WinForms de .NET 4.8 (accessibilite UI Automation : noms des controles visibles par les tests),
// sans l'exiger : sur un .NET 4.5 a 4.7 l'exe tourne avec le comportement de ces versions
[assembly: System.Runtime.Versioning.TargetFramework(".NETFramework,Version=v4.8", FrameworkDisplayName = ".NET Framework 4.8")]

namespace GescomAgentSetup
{
    static class Program
    {
        [STAThread]
        static int Main(string[] args)
        {
            Options o = Options.Parse(args);
            if (o.DetectOut != null || o.TestDbOut != null) return RunCli(o);
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            // erreur imprevue : dans la zone "Details" de la fenetre (et un fichier), pas la boite de dialogue de .NET
            Application.SetUnhandledExceptionMode(UnhandledExceptionMode.CatchException);
            Application.ThreadException += delegate(object s, ThreadExceptionEventArgs e) { ReportCrash(e.Exception); };
            AppDomain.CurrentDomain.UnhandledException += delegate(object s, UnhandledExceptionEventArgs e)
            {
                ReportCrash(e.ExceptionObject as Exception);
            };
            Application.Run(new SetupForm(o));
            return 0;
        }

        public static string CrashFile
        {
            get { return Path.Combine(Path.GetTempPath(), "GescomAgent-Setup-erreur.txt"); }
        }

        static void ReportCrash(Exception ex)
        {
            string text = string.Format("{0:yyyy-MM-dd HH:mm:ss} {1}", DateTime.Now, ex);
            try { File.AppendAllText(CrashFile, text + Environment.NewLine, new UTF8Encoding(false)); } catch (Exception) { }
            SetupForm form = Application.OpenForms.Count > 0 ? Application.OpenForms[0] as SetupForm : null;
            if (form != null) form.ShowCrash(text);
        }

        // Sans fenetre, pour les tests (agent/tests/run-tests.ps1) : ecrit en JSON ce que l'installateur trouve et la
        // tache qu'il creerait, et avec /testdb le resultat du test de lecture de la base. Ne modifie rien, pas besoin d'etre Administrateur.
        static int RunCli(Options o)
        {
            Installer inst = new Installer(o.InstallDir, o.TaskName, AppDomain.CurrentDomain.BaseDirectory, null);
            Detection d = inst.Detect();
            Dictionary<string, object> json = d.ToJson();
            // la tache que l'installation creerait (rien n'est enregistre) : comparee a celle d'install.ps1 -Detect
            json["task_xml"] = inst.TaskXml();
            int code = 0;
            if (o.TestDbOut != null)
            {
                DbTestResult r;
                if (d.Database == null || d.Isql == null) r = new DbTestResult { Error = "base ou isql introuvable" };
                else
                {
                    string tmp = Path.Combine(Path.GetTempPath(), "GescomAgentSetup-" + Guid.NewGuid().ToString("N"));
                    r = Installer.TestDatabase(d.Isql, "localhost:" + d.Database, "SYSDBA", o.DbPassword ?? "masterkey", tmp);
                    try { Directory.Delete(tmp, true); } catch (Exception) { }
                }
                json["db_ok"] = r.Ok;
                json["db_items"] = r.Items;
                json["db_fields"] = r.Fields.Count;
                json["db_error"] = r.Error;
                if (!r.Ok) code = 1;
            }
            File.WriteAllText(o.TestDbOut ?? o.DetectOut, new JavaScriptSerializer().Serialize(json), new UTF8Encoding(false));
            return code;
        }
    }

    // Arguments facultatifs (tests, depannage) : /dir:<dossier> /task:<tache> /api:<url> /lang:ar|fr|en
    // Sans fenetre : /detect:<fichier.json>, /testdb:<fichier.json> [/dbpass:<mot de passe SYSDBA>]
    class Options
    {
        public string InstallDir;
        public string TaskName;
        public string ApiUrl;
        public string Lang = "ar";
        public string DetectOut;
        public string TestDbOut;
        public string DbPassword;

        public static Options Parse(string[] args)
        {
            Options o = new Options();
            foreach (string a in args)
            {
                Match m = Regex.Match(a, "^[/-](dir|task|api|lang|detect|testdb|dbpass):(.+)$", RegexOptions.IgnoreCase);
                if (!m.Success) continue;
                string v = m.Groups[2].Value.Trim();
                switch (m.Groups[1].Value.ToLowerInvariant())
                {
                    case "dir": o.InstallDir = v; break;
                    case "task": o.TaskName = v; break;
                    case "api": o.ApiUrl = v; break;
                    case "lang": if (Array.IndexOf(Texts.Langs, v.ToLowerInvariant()) >= 0) o.Lang = v.ToLowerInvariant(); break;
                    case "detect": o.DetectOut = v; break;
                    case "testdb": o.TestDbOut = v; break;
                    case "dbpass": o.DbPassword = m.Groups[2].Value; break;
                }
            }
            return o;
        }
    }

    // Textes de la fenetre : arabe, francais, anglais (memes langues que le tableau de bord).
    static class Texts
    {
        public static readonly string[] Langs = { "ar", "fr", "en" };

        static readonly Dictionary<string, string[]> All = new Dictionary<string, string[]>
        {
            { "window_title", new[] { "تركيب وكيل المخزون — GescomAgent", "Installation de l'agent de stock — GescomAgent", "Stock agent setup — GescomAgent" } },
            { "title", new[] { "تركيب وكيل المخزون", "Installation de l'agent de stock", "Stock agent setup" } },
            { "subtitle", new[] {
                "GescomAgent {0}: يرسل مخزون Gescomm في هذا الفرع إلى اللوحة (قراءة فقط)",
                "GescomAgent {0} : envoie le stock Gescomm de cette succursale au tableau de bord (lecture seule)",
                "GescomAgent {0}: sends this branch's Gescomm stock to the dashboard (read-only)" } },
            { "found", new[] { "ما وجده المثبّت على هذا الجهاز", "Trouvé sur ce PC", "Found on this PC" } },
            { "row_db", new[] { "قاعدة Gescomm", "Base Gescomm", "Gescomm database" } },
            { "row_isql", new[] { "InterBase (isql)", "InterBase (isql)", "InterBase (isql)" } },
            { "row_service", new[] { "خدمة InterBase", "Service InterBase", "InterBase service" } },
            { "searching", new[] { "جارٍ البحث…", "Recherche…", "Searching…" } },
            { "last_write", new[] { "آخر كتابة {0}", "dernière écriture {0}", "last written {0}" } },
            { "not_found", new[] { "غير موجود", "introuvable", "not found" } },
            { "running", new[] { "تعمل", "démarré", "running" } },
            { "stopped", new[] { "متوقفة", "arrêté", "stopped" } },
            { "client_pc", new[] {
                "Gescomm موجود في {0} لكن قاعدته ليست على هذا الجهاز. ركّب الوكيل على السيرفر الذي فيه القاعدة، أو اختر الملف في «إعدادات متقدمة».",
                "Gescomm est dans {0} mais sa base n'est pas sur ce PC. Installez l'agent sur le serveur qui contient la base, ou choisissez le fichier dans « Paramètres avancés ».",
                "Gescomm is in {0} but its database is not on this PC. Install the agent on the server that holds the database, or pick the file under “Advanced settings”." } },
            { "no_gescomm", new[] {
                "لم يُعثر على Gescomm في هذا الجهاز. ركّب الوكيل على سيرفر Gescomm، أو اختر ملف القاعدة في «إعدادات متقدمة».",
                "Gescomm introuvable sur ce PC. Installez l'agent sur le serveur Gescomm, ou choisissez le fichier de la base dans « Paramètres avancés ».",
                "Gescomm was not found on this PC. Install the agent on the Gescomm server, or pick the database file under “Advanced settings”." } },
            { "newer_db", new[] {
                "تنبيه: في نفس المجلد ملفات قاعدة أحدث من {0}: {1}. تأكّد أن Gescomm يعمل على {0}.",
                "Attention : des bases plus récentes que {0} sont dans le même dossier : {1}. Vérifiez que Gescomm travaille bien sur {0}.",
                "Warning: database files newer than {0} are in the same folder: {1}. Check that Gescomm really works on {0}." } },
            { "branch_name", new[] { "اسم الفرع", "Nom de la succursale", "Branch name" } },
            { "install_code", new[] { "كود التركيب", "Code d'installation", "Install code" } },
            { "code_hint", new[] {
                "من اللوحة: الفروع ← كود تركيب جديد (مثلاً K7P-4QX-92M)",
                "Succursales → Nouveau code d'installation (ex. K7P-4QX-92M)",
                "Branches → New install code (e.g. K7P-4QX-92M)" } },
            { "db_password", new[] { "كلمة سر SYSDBA", "Mot de passe SYSDBA", "SYSDBA password" } },
            { "advanced_show", new[] { "إعدادات متقدمة ▾", "Paramètres avancés ▾", "Advanced settings ▾" } },
            { "advanced_hide", new[] { "إعدادات متقدمة ▴", "Paramètres avancés ▴", "Advanced settings ▴" } },
            { "api_url", new[] { "عنوان الـAPI", "Adresse de l'API", "API address" } },
            { "db_file", new[] { "ملف القاعدة", "Fichier de la base", "Database file" } },
            { "choose_db", new[] { "اختر ملف قاعدة Gescomm (LABASEN.IB)", "Choisir la base Gescomm (LABASEN.IB)", "Choose the Gescomm database (LABASEN.IB)" } },
            { "install", new[] { "تركيب", "Installer", "Install" } },
            { "close", new[] { "إغلاق", "Fermer", "Close" } },
            { "details", new[] { "التفاصيل", "Détails", "Details" } },
            { "installed_head", new[] { "الوكيل مركّب على هذا الجهاز ✓", "L'agent est installé sur ce PC ✓", "The agent is installed on this PC ✓" } },
            { "installed_info", new[] {
                "الفرع: {0}\nالإصدار المركّب: {1} — الإصدار في هذا الملف: {2}\nالقاعدة: {3}",
                "Succursale : {0}\nVersion installée : {1} — version de ce paquet : {2}\nBase : {3}",
                "Branch: {0}\nInstalled version: {1}, this package: {2}\nDatabase: {3}" } },
            { "update", new[] { "تحديث الوكيل", "Mettre à jour l'agent", "Update the agent" } },
            { "diagnose", new[] { "تشخيص", "Diagnostic", "Diagnose" } },
            { "uninstall", new[] { "إزالة", "Désinstaller", "Uninstall" } },
            { "confirm_uninstall", new[] {
                "إزالة الوكيل من هذا الجهاز؟\nبعدها عطّل الفرع أو احذفه من اللوحة.",
                "Désinstaller l'agent de ce PC ?\nEnsuite, désactivez ou supprimez la succursale dans le tableau de bord.",
                "Uninstall the agent from this PC?\nThen disable or delete the branch in the dashboard." } },
            { "step", new[] { "الخطوة {0} من 6: {1}…", "Étape {0}/6 : {1}…", "Step {0} of 6: {1}…" } },
            { "step_1", new[] { "التحقق من المعلومات", "vérification des informations", "checking the information" } },
            { "step_2", new[] { "إنشاء مجلد الوكيل", "dossier de l'agent", "agent folder" } },
            { "step_3", new[] { "اختبار قاعدة Gescomm (قراءة فقط)", "test de la base Gescomm (lecture seule)", "testing the Gescomm database (read-only)" } },
            { "step_4", new[] { "تسجيل الفرع في اللوحة", "enregistrement de la succursale", "registering the branch" } },
            { "step_5", new[] { "اختبار كامل", "test complet", "full test" } },
            { "step_6", new[] { "إنشاء المهمة المجدولة", "tâche planifiée", "scheduled task" } },
            { "working", new[] { "جارٍ التنفيذ…", "En cours…", "Working…" } },
            { "ok_installed", new[] {
                "تمّ التركيب ✓ الفرع يظهر «متصل» في اللوحة خلال دقيقة.",
                "Installation terminée ✓ La succursale apparaît « En ligne » dans le tableau de bord d'ici une minute.",
                "Installed ✓ The branch shows as Online in the dashboard within a minute." } },
            { "ok_updated", new[] { "تمّ تحديث الوكيل ✓", "Agent mis à jour ✓", "Agent updated ✓" } },
            { "ok_uninstalled", new[] {
                "تمّت الإزالة ✓ لا تنسَ تعطيل الفرع أو حذفه من اللوحة.",
                "Agent désinstallé ✓ Pensez à désactiver ou supprimer la succursale dans le tableau de bord.",
                "Agent uninstalled ✓ Remember to disable or delete the branch in the dashboard." } },
            { "ok_diagnose", new[] { "التقرير محفوظ: {0}", "Rapport enregistré : {0}", "Report saved: {0}" } },
            { "err_fields", new[] {
                "اكتب اسم الفرع وكود التركيب.",
                "Saisissez le nom de la succursale et le code d'installation.",
                "Enter the branch name and the install code." } },
            { "err_api", new[] { "عنوان الـAPI يجب أن يبدأ بـ https://", "L'adresse de l'API doit commencer par https://", "The API address must start with https://" } },
            { "err_admin", new[] {
                "يجب تشغيل المثبّت كمسؤول (Administrateur).",
                "Lancez l'installateur en tant qu'administrateur.",
                "Run the installer as administrator." } },
            { "err_files", new[] {
                "ملفات الوكيل ليست بجانب المثبّت. فكّ ضغط الملف كاملاً (زر يمين ← Extraire tout)، ثم شغّل GescomAgent-Setup.exe من المجلد.",
                "Les fichiers de l'agent ne sont pas à côté de l'installateur. Extrayez tout le zip (clic droit → Extraire tout), puis lancez GescomAgent-Setup.exe depuis le dossier.",
                "The agent files are not next to the installer. Extract the whole zip (right-click → Extract All), then run GescomAgent-Setup.exe from the folder." } },
            { "err_installed", new[] { "الوكيل مركّب مسبقاً على هذا الجهاز.", "L'agent est déjà installé sur ce PC.", "The agent is already installed on this PC." } },
            { "err_not_installed", new[] { "الوكيل غير مركّب على هذا الجهاز.", "L'agent n'est pas installé sur ce PC.", "The agent is not installed on this PC." } },
            { "err_isql", new[] {
                "لم يُعثر على isql.exe: هل InterBase مثبّت على هذا الجهاز؟",
                "isql.exe introuvable : InterBase est-il installé sur ce PC ?",
                "isql.exe not found: is InterBase installed on this PC?" } },
            { "err_db", new[] {
                "لم يُعثر على ملف القاعدة. اختره في «إعدادات متقدمة».",
                "Fichier de la base introuvable. Choisissez-le dans « Paramètres avancés ».",
                "Database file not found. Pick it under “Advanced settings”." } },
            { "err_db_test", new[] {
                "تعذّر فتح القاعدة أو الجدول GC003. إذا كانت كلمة سر SYSDBA ليست masterkey، اكتبها ثم اضغط «تركيب» من جديد.",
                "Impossible d'ouvrir la base ou la table GC003. Si le mot de passe SYSDBA n'est pas masterkey, saisissez-le puis cliquez de nouveau sur « Installer ».",
                "Could not open the database or the GC003 table. If the SYSDBA password is not masterkey, type it and click “Install” again." } },
            { "err_code", new[] {
                "اللوحة رفضت الكود: خاطئ أو منتهٍ أو مستعمل. أنشئ كوداً جديداً من اللوحة.",
                "Le tableau de bord a refusé le code : erroné, expiré ou déjà utilisé. Créez-en un nouveau.",
                "The dashboard refused the code: wrong, expired or already used. Create a new one." } },
            { "err_network", new[] {
                "تعذّر الاتصال باللوحة. تحقّق من الإنترنت ثم أعد المحاولة (التفاصيل في الأسفل).",
                "Connexion au tableau de bord impossible. Vérifiez internet puis réessayez (détails ci-dessous).",
                "Could not reach the dashboard. Check the internet connection and try again (details below)." } },
            { "err_full_test", new[] { "الاختبار الكامل فشل. راجع التفاصيل في الأسفل.", "Le test complet a échoué. Voir les détails ci-dessous.", "The full test failed. See the details below." } },
            { "err_task", new[] { "تعذّر إنشاء المهمة المجدولة. راجع التفاصيل في الأسفل.", "Impossible de créer la tâche planifiée. Voir les détails ci-dessous.", "Could not create the scheduled task. See the details below." } },
            { "err_generic", new[] { "فشلت العملية (الرمز {0}). راجع التفاصيل في الأسفل.", "Échec (code {0}). Voir les détails ci-dessous.", "Failed (code {0}). See the details below." } },
            { "err_detect", new[] { "تعذّر فحص هذا الجهاز. راجع التفاصيل في الأسفل.", "Analyse de ce PC impossible. Voir les détails ci-dessous.", "Could not check this PC. See the details below." } },
            { "busy", new[] { "العملية جارية، انتظر حتى تنتهي.", "Opération en cours, patientez.", "Please wait for the current operation to finish." } },
        };

        public static string Get(string lang, string key, params object[] args)
        {
            string[] t;
            if (!All.TryGetValue(key, out t)) return key;
            string s = t[Math.Max(0, Array.IndexOf(Langs, lang))];
            return args.Length > 0 ? string.Format(s, args) : s;
        }
    }
}
