// Logique d'installation de GescomAgent, sans fenetre : detection, installation, mise a jour, desinstallation, diagnostic.
// Fait tout elle-meme (registre, isql, HTTPS, Planificateur de taches) et ne lance jamais PowerShell :
// AVG bloque les scripts PowerShell lances par un exe inconnu (detection IDP.HELU.PSD11, vue sur DESKTOP-9H7CGGI).
// Memes etapes et memes regles que install.ps1 (lecture seule sur la base, code consomme apres le test de la base).
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Reflection;
using System.Security;
using System.Security.AccessControl;
using System.Security.Principal;
using System.ServiceProcess;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Web.Script.Serialization;
using Microsoft.Win32;

namespace GescomAgentSetup
{
    // Echec d'une etape : Code = etape (comme les codes de sortie documentes dans le README de l'agent)
    class SetupException : Exception
    {
        public readonly int Code;
        public SetupException(int code, string message) : base(message) { Code = code; }
    }

    class InstalledAgent
    {
        public string BranchName, ApiUrl, Database, Version;
        public bool Registered;
    }

    class Detection
    {
        public string Database;              // chemin local de LABASEN.IB, null si introuvable
        public DateTime DatabaseTime;
        public List<string> NewerDatabases = new List<string>();
        public string GescommDir;            // dossier de Gescomm.exe, meme sans base (poste client)
        public string Isql;
        public bool InterBaseRunning;
        public bool IsAdmin;
        public string PackageVersion;
        public InstalledAgent Installed;     // null : pas installe (ou illisible sans droits Administrateur)

        public Dictionary<string, object> ToJson()
        {
            Dictionary<string, object> d = new Dictionary<string, object>();
            d["database"] = Database;
            d["database_mtime"] = Database == null ? null : DatabaseTime.ToString("yyyy-MM-dd HH:mm");
            d["newer_databases"] = NewerDatabases;
            d["gescomm_dir"] = GescommDir;
            d["isql"] = Isql;
            d["interbase_running"] = InterBaseRunning;
            d["is_admin"] = IsAdmin;
            d["package_version"] = PackageVersion;
            if (Installed != null)
            {
                Dictionary<string, object> i = new Dictionary<string, object>();
                i["branch_name"] = Installed.BranchName;
                i["api_url"] = Installed.ApiUrl;
                i["database"] = Installed.Database;
                i["version"] = Installed.Version;
                i["registered"] = Installed.Registered;
                d["installed"] = i;
            }
            else d["installed"] = null;
            return d;
        }
    }

    class DbTestResult
    {
        public bool Ok;
        public int Items;
        public List<string> Fields = new List<string>();
        public string Error;
    }

    class Installer
    {
        public const string DefaultApiUrl = "https://stockallal.souftech.com/api";
        public const string DbFileName = "LABASEN.IB";   // Gescomm.exe ouvre LaBaseN.ib dans son propre dossier
        public static readonly string[] RequiredFields = { "CODEA", "DESIGNATIONF2", "STOCKREEL" };   // $Fields d'agent.ps1
        const string ReadOnlyTx = "SET TRANSACTION READ ONLY NO WAIT ISOLATION LEVEL READ COMMITTED RECORD_VERSION;";

        public readonly string Dir, ConfigPath, AgentPath, TaskName, PackageDir;
        readonly Action<string> log;
        static readonly JavaScriptSerializer Json = new JavaScriptSerializer();

        public Installer(string installDir, string taskName, string packageDir, Action<string> logLine)
        {
            Dir = string.IsNullOrEmpty(installDir)
                ? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "GescomAgent")
                : Path.GetFullPath(installDir);
            TaskName = string.IsNullOrEmpty(taskName) ? "GescomAgent" : taskName;
            ConfigPath = Path.Combine(Dir, "config.json");
            AgentPath = Path.Combine(Dir, "agent.ps1");
            PackageDir = packageDir;
            log = logLine ?? new Action<string>(delegate { });
        }

        string PackageAgent { get { return Path.Combine(PackageDir, "agent.ps1"); } }

        void Log(string line) { log(line); }

        void Step(int n, string title) { Log(""); Log(string.Format("== {0}/6 {1}", n, title)); }

        // ---------- detection ----------

        public static bool IsAdmin()
        {
            return new WindowsPrincipal(WindowsIdentity.GetCurrent()).IsInRole(WindowsBuiltInRole.Administrator);
        }

        public static string ReadAgentVersion(string path)
        {
            try
            {
                Match m = Regex.Match(File.ReadAllText(path), "AgentVersion = '([^']+)'");
                return m.Success ? m.Groups[1].Value : null;
            }
            catch (Exception) { return null; }
        }

        static string RegString(RegistryKey k, string name)
        {
            object v = k.GetValue(name);
            return v == null ? null : v.ToString();
        }

        // Dossiers ou Gescomm peut etre installe, du plus sur au moins sur (meme ordre qu'install.ps1).
        public static List<string> GescommDirs()
        {
            List<string> dirs = new List<string>();
            foreach (RegistryView view in new[] { RegistryView.Registry32, RegistryView.Registry64 })
            {
                try
                {
                    using (RegistryKey hklm = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view))
                    using (RegistryKey un = hklm.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"))
                    {
                        if (un == null) continue;
                        foreach (string sub in un.GetSubKeyNames())
                        {
                            using (RegistryKey k = un.OpenSubKey(sub))
                            {
                                string display = k == null ? null : RegString(k, "DisplayName");
                                if (display == null || !display.StartsWith("Gescom", StringComparison.OrdinalIgnoreCase)) continue;
                                string loc = RegString(k, "InstallLocation");
                                if (!string.IsNullOrEmpty(loc)) dirs.Add(loc);
                                foreach (string s in new[] { RegString(k, "DisplayIcon"), RegString(k, "UninstallString") })
                                {
                                    Match m = Regex.Match(s ?? "", @"[A-Za-z]:\\[^""]*?\.exe", RegexOptions.IgnoreCase);
                                    if (m.Success) dirs.Add(Path.GetDirectoryName(m.Value));
                                }
                            }
                        }
                    }
                }
                catch (Exception) { }
            }
            foreach (Process p in Process.GetProcessesByName("Gescomm"))
            {
                try { dirs.Add(Path.GetDirectoryName(p.MainModule.FileName)); }
                catch (Exception) { }   // autre session ou droits insuffisants
                finally { p.Dispose(); }
            }
            foreach (DriveInfo d in DriveInfo.GetDrives())
            {
                try
                {
                    if (d.DriveType != DriveType.Fixed || !d.IsReady) continue;
                    dirs.Add(Path.Combine(d.RootDirectory.FullName, "Gescomm"));
                    dirs.Add(Path.Combine(d.RootDirectory.FullName, "Gescom"));
                }
                catch (Exception) { }
            }
            return dirs;
        }

        public static string FindIsql()
        {
            List<string> roots = new List<string>();
            foreach (RegistryView view in new[] { RegistryView.Registry32, RegistryView.Registry64 })
            {
                try
                {
                    using (RegistryKey hklm = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view))
                    {
                        foreach (string path in new[] { @"SOFTWARE\Borland\InterBase\Servers", @"SOFTWARE\Borland\InterBase\CurrentVersion" })
                        {
                            using (RegistryKey k = hklm.OpenSubKey(path))
                            {
                                if (k == null) continue;
                                roots.Add(RegString(k, "RootDirectory"));
                                // une instance par sous-cle (Servers\gds_db) selon la version d'InterBase
                                foreach (string sub in k.GetSubKeyNames())
                                {
                                    using (RegistryKey s = k.OpenSubKey(sub)) { if (s != null) roots.Add(RegString(s, "RootDirectory")); }
                                }
                            }
                        }
                    }
                }
                catch (Exception) { }
            }
            foreach (string env in new[] { "ProgramFiles(x86)", "ProgramFiles" })
            {
                string pf = Environment.GetEnvironmentVariable(env);
                if (!string.IsNullOrEmpty(pf)) roots.Add(Path.Combine(pf, @"Borland\InterBase"));
            }
            foreach (string r in roots)
            {
                if (string.IsNullOrEmpty(r)) continue;
                string f = Path.Combine(r, @"bin\isql.exe");
                if (File.Exists(f)) return f;
            }
            return null;
        }

        public static bool InterBaseRunning()
        {
            try
            {
                foreach (ServiceController s in ServiceController.GetServices())
                {
                    using (s)
                    {
                        string n = s.ServiceName;
                        if ((n.StartsWith("IBS_", StringComparison.OrdinalIgnoreCase) || n.Equals("InterBaseServer", StringComparison.OrdinalIgnoreCase))
                            && s.Status == ServiceControllerStatus.Running) return true;
                    }
                }
            }
            catch (Exception) { }
            return false;
        }

        public Detection Detect()
        {
            Detection d = new Detection();
            List<string> dirs = GescommDirs();
            foreach (string dir in dirs)
            {
                try
                {
                    string f = Path.Combine(dir, DbFileName);
                    if (d.Database == null && File.Exists(f)) d.Database = Path.GetFullPath(f);
                    if (d.GescommDir == null && File.Exists(Path.Combine(dir, "Gescomm.exe"))) d.GescommDir = Path.GetFullPath(dir).TrimEnd('\\');
                }
                catch (Exception) { }
            }
            if (d.Database != null)
            {
                FileInfo db = new FileInfo(d.Database);
                d.DatabaseTime = db.LastWriteTime;
                d.GescommDir = db.DirectoryName;
                // autre .ib du dossier ecrit apres : Gescomm travaille peut-etre sur un autre fichier.
                // MLABASEG94.IB (base annexe de Gescomm) change a chaque ouverture : ignore.
                foreach (FileInfo f in db.Directory.GetFiles("*.ib"))
                {
                    if (f.Extension.Equals(".ib", StringComparison.OrdinalIgnoreCase)
                        && !f.Name.Equals(db.Name, StringComparison.OrdinalIgnoreCase)
                        && !f.Name.StartsWith("MLABASE", StringComparison.OrdinalIgnoreCase)
                        && f.LastWriteTime > db.LastWriteTime.AddMinutes(1)) d.NewerDatabases.Add(f.Name);
                }
            }
            d.Isql = FindIsql();
            d.InterBaseRunning = InterBaseRunning();
            d.IsAdmin = IsAdmin();
            d.PackageVersion = ReadAgentVersion(PackageAgent);
            d.Installed = ReadInstalled();
            return d;
        }

        Dictionary<string, object> ReadConfig()
        {
            try
            {
                if (!File.Exists(ConfigPath)) return null;
                return Json.DeserializeObject(File.ReadAllText(ConfigPath, Encoding.UTF8)) as Dictionary<string, object>;
            }
            catch (Exception) { return null; }   // sans droits Administrateur le dossier est illisible
        }

        static string Str(Dictionary<string, object> d, string key)
        {
            object v;
            return d != null && d.TryGetValue(key, out v) && v != null ? v.ToString() : null;
        }

        InstalledAgent ReadInstalled()
        {
            Dictionary<string, object> c = ReadConfig();
            if (c == null) return null;
            InstalledAgent i = new InstalledAgent();
            i.BranchName = Str(c, "branch_name");
            i.ApiUrl = Str(c, "api_url");
            i.Database = Str(c, "database");
            i.Registered = !string.IsNullOrEmpty(Str(c, "token"));
            i.Version = ReadAgentVersion(AgentPath);
            return i;
        }

        // ---------- base Gescomm (isql, lecture seule) ----------

        // Verifie la connexion, la table GC003 et ses colonnes, comme agent.ps1 -SelfTest -SkipApi.
        public static DbTestResult TestDatabase(string isql, string database, string user, string password, string tmpDir)
        {
            DbTestResult r = new DbTestResult();
            Encoding ansi = Encoding.GetEncoding(1252);   // base en charset NONE : octets Windows-1252
            Directory.CreateDirectory(tmpDir);
            string sql = Path.Combine(tmpDir, Guid.NewGuid().ToString("N") + ".sql");
            File.WriteAllLines(sql, new[] {
                ReadOnlyTx,
                "SET LIST ON;",
                "SELECT RDB$FIELD_NAME FROM RDB$RELATION_FIELDS WHERE RDB$RELATION_NAME = 'GC003';",
                "SELECT COUNT(*) AS NB_ARTICLES FROM GC003;",
            }, ansi);
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo(isql, "-q -i \"" + sql + "\" \"" + database + "\"");
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.RedirectStandardOutput = true;
                psi.RedirectStandardError = true;
                psi.StandardOutputEncoding = ansi;
                psi.StandardErrorEncoding = ansi;
                psi.EnvironmentVariables["ISC_USER"] = user;
                psi.EnvironmentVariables["ISC_PASSWORD"] = password;
                StringBuilder output = new StringBuilder(), errors = new StringBuilder();
                using (Process p = new Process())
                {
                    p.StartInfo = psi;
                    p.OutputDataReceived += delegate(object s, DataReceivedEventArgs e) { if (e.Data != null) lock (output) output.AppendLine(e.Data); };
                    p.ErrorDataReceived += delegate(object s, DataReceivedEventArgs e) { if (e.Data != null) lock (errors) errors.AppendLine(e.Data); };
                    p.Start();
                    p.BeginOutputReadLine();
                    p.BeginErrorReadLine();
                    if (!p.WaitForExit(120000))
                    {
                        try { p.Kill(); } catch (Exception) { }
                        r.Error = "isql : delai depasse (120 s)";
                        return r;
                    }
                    p.WaitForExit();
                }
                // "Rolling back work." = fin normale de la transaction par defaut d'isql, pas une erreur
                List<string> errLines = new List<string>();
                foreach (string l in errors.ToString().Split('\n'))
                {
                    string t = l.Trim();
                    if (t.Length > 0 && t != "Rolling back work.") errLines.Add(t);
                }
                // sortie SET LIST ON : nom du champ sur 32 colonnes, puis la valeur
                foreach (string raw in output.ToString().Split('\n'))
                {
                    string line = raw.TrimEnd('\r');
                    if (line.Length <= 32) continue;
                    string label = line.Substring(0, 32).Trim(), value = line.Substring(32).Trim();
                    if (label == "RDB$FIELD_NAME") r.Fields.Add(value);
                    else if (label == "NB_ARTICLES") int.TryParse(value, out r.Items);
                }
                List<string> missing = new List<string>();
                foreach (string f in RequiredFields) { if (!r.Fields.Contains(f)) missing.Add(f); }
                if (errLines.Count > 0) r.Error = "isql : " + string.Join(" | ", errLines.ToArray());
                else if (missing.Count > 0) r.Error = "table GC003 : colonnes absentes : " + string.Join(", ", missing.ToArray());
                else r.Ok = true;
                return r;
            }
            catch (Exception ex)
            {
                r.Error = ex.Message;
                return r;
            }
            finally
            {
                try { File.Delete(sql); } catch (Exception) { }
            }
        }

        // ---------- API centrale ----------

        static Installer()
        {
            // TLS 1.2 (valeur 3072) : absent de l'enumeration de .NET 4.0, present des que .NET 4.5 est installe
            try { ServicePointManager.SecurityProtocol |= (SecurityProtocolType)3072; } catch (NotSupportedException) { }
        }

        class HttpResult
        {
            public int Status;
            public Dictionary<string, object> Body;
            public string Text;
        }

        // Connexion instable dans certaines branches : nouvel essai, mais une requete qui a pu atteindre le serveur
        // n'est jamais rejouee si elle n'est pas idempotente (enregistrement : code a usage unique).
        HttpResult Http(string method, string url, string token, Dictionary<string, object> body, string agentVersion, bool idempotent)
        {
            for (int attempt = 1; ; attempt++)
            {
                try
                {
                    return SendHttp(method, url, token, body, agentVersion);
                }
                catch (WebException ex)
                {
                    bool neverSent = ex.Status == WebExceptionStatus.ConnectFailure || ex.Status == WebExceptionStatus.NameResolutionFailure;
                    if (attempt >= 4 || !(idempotent || neverSent)) throw new SetupException(0, "API : " + ex.Message);
                    Log(string.Format("API : {0} (nouvel essai {1}/3)", ex.Message, attempt));
                    Thread.Sleep(2000 * attempt);
                }
            }
        }

        static HttpResult SendHttp(string method, string url, string token, Dictionary<string, object> body, string agentVersion)
        {
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.Method = method;
            req.Accept = "application/json";
            req.Timeout = req.ReadWriteTimeout = 60000;
            req.Headers["X-Agent-Version"] = agentVersion ?? "?";
            if (token != null) req.Headers["Authorization"] = "Bearer " + token;
            if (body != null)
            {
                byte[] bytes = Encoding.UTF8.GetBytes(Json.Serialize(body));
                req.ContentType = "application/json; charset=utf-8";
                req.ContentLength = bytes.Length;
                using (Stream s = req.GetRequestStream()) s.Write(bytes, 0, bytes.Length);
            }
            HttpWebResponse resp;
            try { resp = (HttpWebResponse)req.GetResponse(); }
            catch (WebException ex)
            {
                // reponse HTTP d'erreur (422, 401...) : vraie reponse du serveur, pas un probleme de reseau
                if (ex.Response == null) throw;
                resp = (HttpWebResponse)ex.Response;
            }
            using (resp)
            using (StreamReader r = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
            {
                HttpResult h = new HttpResult();
                h.Status = (int)resp.StatusCode;
                h.Text = r.ReadToEnd();
                try { h.Body = Json.DeserializeObject(h.Text) as Dictionary<string, object>; } catch (Exception) { }
                return h;
            }
        }

        static bool IsAllowedApiUrl(string api)
        {
            return Regex.IsMatch(api, @"^(https://|http://(localhost|127\.0\.0\.1)(:\d+)?(/|$))", RegexOptions.IgnoreCase);
        }

        // ---------- fichiers ----------

        static string J(object v) { return Json.Serialize(v); }

        void WriteConfig(string api, string token, string branch, string database, string isql, string user, string password)
        {
            // meme forme que celle ecrite par install.ps1, relue par agent.ps1 (ConvertFrom-Json)
            string text = "{\n"
                + "    \"api_url\":  " + J(api) + ",\n"
                + "    \"token\":  " + J(token) + ",\n"
                + "    \"branch_name\":  " + J(branch) + ",\n"
                + "    \"database\":  " + J(database) + ",\n"
                + "    \"isql\":  " + J(isql) + ",\n"
                + "    \"db_user\":  " + J(user) + ",\n"
                + "    \"db_password\":  " + J(password) + ",\n"
                + "    \"poll_seconds\":  10,\n"
                + "    \"full_sync_minutes\":  5\n"
                + "}";
            File.WriteAllText(ConfigPath, text, new UTF8Encoding(false));
        }

        // Dossier lisible seulement par SYSTEM et les Administrateurs (il contient le token et le mot de passe de la base).
        // SID plutot que noms : fonctionne sur un Windows francais ("Administrateurs") comme anglais.
        void ProtectDir()
        {
            DirectorySecurity sec = new DirectorySecurity();
            sec.SetAccessRuleProtection(true, false);
            foreach (WellKnownSidType t in new[] { WellKnownSidType.LocalSystemSid, WellKnownSidType.BuiltinAdministratorsSid })
            {
                sec.AddAccessRule(new FileSystemAccessRule(new SecurityIdentifier(t, null), FileSystemRights.FullControl,
                    InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
            }
            Directory.SetAccessControl(Dir, sec);
        }

        // ---------- Planificateur de taches (API COM, pas schtasks.exe) ----------

        static object Com(object o, string method, params object[] args)
        {
            return o.GetType().InvokeMember(method, BindingFlags.InvokeMethod, null, o, args);
        }

        static object ComGet(object o, string property)
        {
            return o.GetType().InvokeMember(property, BindingFlags.GetProperty, null, o, null);
        }

        static object TaskFolder()
        {
            object svc = Activator.CreateInstance(Type.GetTypeFromProgID("Schedule.Service"));
            Com(svc, "Connect");
            return Com(svc, "GetFolder", "\\");
        }

        object FindTask()
        {
            try { return Com(TaskFolder(), "GetTask", TaskName); }
            catch (TargetInvocationException) { return null; }   // tache absente
        }

        // Retour de veille, d'hibernation et du "demarrage rapide" de Windows (Power-Troubleshooter, evenement 1).
        public const string ResumeQuery = "<QueryList><Query Id=\"0\" Path=\"System\"><Select Path=\"System\">"
            + "*[System[Provider[@Name='Microsoft-Windows-Power-Troubleshooter'] and EventID=1]]</Select></Query></QueryList>";

        // Meme XML qu'install.ps1 (Get-TaskXml), compare par tests\run-tests.ps1. Trois declencheurs :
        // - demarrage du PC (+1 min : laisse InterBase et le reseau demarrer) ;
        // - retour de veille / d'hibernation / du demarrage rapide (+1 min). Le demarrage rapide est actif par defaut :
        //   "Arreter" met alors Windows en veille prolongee et le rallumage n'est pas un demarrage. Vu sur DESKTOP-9H7CGGI :
        //   arrete le 05/11 a 12:02, rallume le 07/11 a 07:34, la tache lancee une fois a 07:34:41 et arretee (0x41306),
        //   puis plus rien pendant des heures ;
        // - toutes les 5 minutes, sans fin : sans effet si l'agent tourne deja (IgnoreNew), le relance s'il s'est arrete.
        //   Jusqu'a 1.0.8 : quotidien a minuit, repete 1 jour, qui n'a plus rien relance apres ce retour.
        public string TaskXml()
        {
            string args = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"" + AgentPath + "\" -ConfigPath \"" + ConfigPath + "\"";
            // calendrier gregorien quelle que soit la langue de Windows (ar-SA : annee hegirienne avec la culture du PC)
            string today = DateTime.Today.ToString("yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture);
            return "<?xml version=\"1.0\" encoding=\"UTF-16\"?>\n"
                + "<Task version=\"1.2\" xmlns=\"http://schemas.microsoft.com/windows/2004/02/mit/task\">\n"
                + "  <RegistrationInfo><Description>Envoie le stock Gescomm de cette branche vers l'API centrale (lecture seule).</Description></RegistrationInfo>\n"
                + "  <Triggers>\n"
                + "    <BootTrigger><Enabled>true</Enabled><Delay>PT1M</Delay></BootTrigger>\n"
                + "    <EventTrigger><Enabled>true</Enabled><Subscription>" + SecurityElement.Escape(ResumeQuery) + "</Subscription><Delay>PT1M</Delay></EventTrigger>\n"
                + "    <TimeTrigger>\n"
                + "      <Repetition><Interval>PT5M</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition>\n"
                + "      <StartBoundary>" + today + "T00:00:00</StartBoundary>\n"
                + "      <Enabled>true</Enabled>\n"
                + "    </TimeTrigger>\n"
                + "  </Triggers>\n"
                + "  <Principals><Principal id=\"Author\"><UserId>S-1-5-18</UserId><RunLevel>HighestAvailable</RunLevel></Principal></Principals>\n"
                + "  <Settings>\n"
                + "    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>\n"
                + "    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>\n"
                + "    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>\n"
                + "    <StartWhenAvailable>true</StartWhenAvailable>\n"
                + "    <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>\n"
                + "    <RestartOnFailure><Interval>PT1M</Interval><Count>999</Count></RestartOnFailure>\n"
                + "    <AllowStartOnDemand>true</AllowStartOnDemand>\n"
                + "    <Enabled>true</Enabled>\n"
                + "  </Settings>\n"
                + "  <Actions Context=\"Author\"><Exec><Command>powershell.exe</Command><Arguments>"
                + SecurityElement.Escape(args) + "</Arguments></Exec></Actions>\n"
                + "</Task>";
        }

        void RegisterTask()
        {
            const int TASK_CREATE_OR_UPDATE = 6, TASK_LOGON_SERVICE_ACCOUNT = 5;
            object folder = TaskFolder();
            object task = Com(folder, "RegisterTask", TaskName, TaskXml(), TASK_CREATE_OR_UPDATE, "SYSTEM", null, TASK_LOGON_SERVICE_ACCOUNT, null);
            Com(task, "Run", new object[] { null });
        }

        void StopTask(object task)
        {
            try { Com(task, "Stop", 0); } catch (TargetInvocationException) { }
        }

        static Exception Inner(Exception ex)
        {
            TargetInvocationException t = ex as TargetInvocationException;
            return t != null && t.InnerException != null ? t.InnerException : ex;
        }

        // ---------- installation ----------

        public void Install(string apiUrl, string branchName, string installCode, string database, string dbPassword)
        {
            const string dbUser = "SYSDBA";
            Step(1, "Informations");
            if (!IsAdmin()) throw new SetupException(10, "lancez l'installateur en tant qu'Administrateur.");
            if (!File.Exists(PackageAgent)) throw new SetupException(11, "agent.ps1 introuvable a cote de l'installateur (" + PackageAgent + ")");
            InstalledAgent installed = ReadInstalled();
            if (installed != null && installed.Registered)
            {
                throw new SetupException(12, "agent deja installe (branche '" + installed.BranchName + "').");
            }
            string api = (apiUrl ?? "").Trim().TrimEnd('/');
            string branch = (branchName ?? "").Trim();
            string code = (installCode ?? "").Trim();
            if (api.Length == 0 || branch.Length == 0 || code.Length == 0)
            {
                throw new SetupException(20, "adresse API, nom de branche et code d'installation sont obligatoires.");
            }
            if (!IsAllowedApiUrl(api)) throw new SetupException(20, "api_url doit commencer par https:// (http seulement pour un test local)");
            string isql = FindIsql();
            if (isql == null) throw new SetupException(21, "isql introuvable. InterBase est-il installe sur ce PC ?");
            Detection found = Detect();
            string dbPath = string.IsNullOrEmpty(database) ? found.Database : database.Trim().Trim('"');
            if (dbPath != null && dbPath.StartsWith("localhost:", StringComparison.OrdinalIgnoreCase)) dbPath = dbPath.Substring(10);
            if (string.IsNullOrEmpty(dbPath) || !File.Exists(dbPath))
            {
                throw new SetupException(22, "base Gescomm (" + DbFileName + ") introuvable sur ce PC. " + (dbPath ?? ""));
            }
            // TCP par localhost, comme Gescomm (le protocole local d'InterBase echoue souvent sur un Windows recent)
            string db = "localhost:" + Path.GetFullPath(dbPath);
            string password = string.IsNullOrEmpty(dbPassword) ? "masterkey" : dbPassword;
            string version = found.PackageVersion;
            Log("Branche : " + branch);
            Log("API     : " + api);
            Log("Base    : " + db);
            Log("isql    : " + isql);

            Step(2, "Dossier " + Dir + " (acces : SYSTEM et Administrateurs seulement)");
            Directory.CreateDirectory(Dir);
            ProtectDir();
            File.Copy(PackageAgent, AgentPath, true);
            WriteConfig(api, "", branch, db, isql, dbUser, password);
            Log("OK");

            Step(3, "Test de la base Gescomm (lecture seule)");
            DbTestResult t = TestDatabase(isql, db, dbUser, password, Path.Combine(Dir, "tmp"));
            if (!t.Ok) throw new SetupException(30, "base inaccessible ou table GC003 absente : " + t.Error);
            Log(string.Format("Table     : GC003 OK ({0} articles)", t.Items));

            Step(4, "Enregistrement de la branche aupres de l'API");
            Dictionary<string, object> body = new Dictionary<string, object>();
            body["install_code"] = code;
            body["branch_name"] = branch;
            body["hostname"] = Environment.MachineName;
            body["agent_version"] = version;
            HttpResult reg;
            try { reg = Http("POST", api + "/agents/register", null, body, version, false); }
            catch (SetupException ex) { throw new SetupException(40, ex.Message); }
            string token = Str(reg.Body, "token");
            if (reg.Status >= 300 || token == null)
            {
                throw new SetupException(40, string.Format("HTTP {0} : {1}", reg.Status, reg.Text));
            }
            string registeredName = Str(reg.Body, "branch_name") ?? branch;
            WriteConfig(api, token, registeredName, db, isql, dbUser, password);
            Log(string.Format("branche enregistree : '{0}' (id {1})", registeredName, Str(reg.Body, "branch_id")));

            Step(5, "Test complet (base + API)");
            HttpResult ping;
            try { ping = Http("GET", api + "/agent/ping", token, null, version, true); }
            catch (SetupException ex) { throw new SetupException(50, ex.Message); }
            if (ping.Status != 200) throw new SetupException(50, string.Format("HTTP {0} : {1}", ping.Status, ping.Text));
            Log(string.Format("API       : OK, branche '{0}' (id {1})", Str(ping.Body, "branch_name"), Str(ping.Body, "branch_id")));

            Step(6, "Tache planifiee (demarrage du PC, retour de veille + surveillance toutes les 5 minutes)");
            try { RegisterTask(); }
            catch (Exception ex) { throw new SetupException(60, "tache planifiee : " + Inner(ex).Message); }

            Log("");
            Log("Installation terminee.");
            Log("Journal : " + Path.Combine(Dir, @"logs\agent-" + DateTime.Now.ToString("yyyyMMdd") + ".log"));
        }

        // Nouvelle version d'agent.ps1 sur une branche deja installee : sans nouvel enregistrement.
        // La tache est toujours reecrite : une branche installee avant 1.0.9 recoit ainsi les declencheurs d'aujourd'hui.
        public void Update()
        {
            if (!IsAdmin()) throw new SetupException(10, "lancez l'installateur en tant qu'Administrateur.");
            if (!File.Exists(PackageAgent)) throw new SetupException(11, "agent.ps1 introuvable a cote de l'installateur (" + PackageAgent + ")");
            if (!File.Exists(ConfigPath)) throw new SetupException(13, "agent non installe sur ce PC (config.json absent).");
            object task = FindTask();
            if (task != null) StopTask(task);
            File.Copy(PackageAgent, AgentPath, true);
            Log("agent.ps1 copie (version " + ReadAgentVersion(AgentPath) + ").");
            try { RegisterTask(); }
            catch (Exception ex) { throw new SetupException(60, "tache planifiee : " + Inner(ex).Message); }
            Log("Tache planifiee reecrite (demarrage, retour de veille, toutes les 5 minutes) et redemarree.");
        }

        public void Uninstall()
        {
            if (!IsAdmin()) throw new SetupException(10, "lancez l'installateur en tant qu'Administrateur.");
            object task = FindTask();
            if (task != null)
            {
                StopTask(task);
                Com(TaskFolder(), "DeleteTask", TaskName, 0);
                Log("Tache planifiee supprimee.");
            }
            else Log("Aucune tache planifiee " + TaskName + ".");
            if (Directory.Exists(Dir))
            {
                // l'agent arrete peut garder son journal ouvert quelques instants
                for (int i = 1; ; i++)
                {
                    try { Directory.Delete(Dir, true); break; }
                    catch (IOException) { if (i >= 5) throw; Thread.Sleep(2000); }
                    catch (UnauthorizedAccessException) { if (i >= 5) throw; Thread.Sleep(2000); }
                }
                Log("Dossier " + Dir + " supprime.");
            }
            Log("Pensez a desactiver la branche dans le tableau de bord : son token reste valide jusque-la.");
        }

        // ---------- diagnostic (comme diagnose.ps1 : jamais le token ni le mot de passe) ----------

        public string Diagnose()
        {
            List<string> lines = new List<string>();
            Action<string> outLine = delegate(string l) { lines.Add(l); Log(l); };
            outLine(string.Format("== Diagnostic GescomAgent {0:yyyy-MM-dd HH:mm:ss} sur {1}", DateTime.Now, Environment.MachineName));

            outLine("");
            outLine("== Fichiers (" + Dir + ")");
            outLine(File.Exists(AgentPath)
                ? "agent.ps1   : present, version " + ReadAgentVersion(AgentPath)
                : "agent.ps1   : ABSENT (supprime ou mis en quarantaine par l'antivirus ?)");
            Dictionary<string, object> cfg = ReadConfig();
            if (cfg != null)
            {
                outLine(string.Format("config.json : api_url={0} | branche={1} | base={2} | token={3}", Str(cfg, "api_url"),
                    Str(cfg, "branch_name"), Str(cfg, "database"), string.IsNullOrEmpty(Str(cfg, "token")) ? "ABSENT" : "present"));
            }
            else outLine("config.json : ABSENT");

            outLine("");
            outLine("== Tache planifiee " + TaskName);
            object task = FindTask();
            if (task != null)
            {
                string[] states = { "inconnu", "desactivee", "en file", "prete", "en cours" };
                int state = Convert.ToInt32(ComGet(task, "State"));
                outLine(string.Format("etat={0} | dernier lancement={1} | resultat=0x{2:X} | prochain={3}",
                    state >= 0 && state < states.Length ? states[state] : state.ToString(), ComGet(task, "LastRunTime"),
                    Convert.ToInt32(ComGet(task, "LastTaskResult")), ComGet(task, "NextRunTime")));
                outLine("declencheurs : " + TriggerSummary(task));
            }
            else outLine("tache ABSENTE");
            outLine("demarrage rapide de Windows : " + FastStartup());

            outLine("");
            outLine("== Journal (30 dernieres lignes)");
            string logDir = Path.Combine(Dir, "logs");
            string[] logs = Directory.Exists(logDir) ? Directory.GetFiles(logDir, "agent-*.log") : new string[0];
            if (logs.Length > 0)
            {
                Array.Sort(logs, StringComparer.Ordinal);
                string last = logs[logs.Length - 1];
                outLine("fichier : " + last);
                string[] all = ReadShared(last);
                for (int i = Math.Max(0, all.Length - 30); i < all.Length; i++) outLine(all[i]);
            }
            else outLine("aucun journal");

            outLine("");
            outLine("== Connexion vers l'API");
            string host = "stockallal.souftech.com";
            try { if (Str(cfg, "api_url") != null) host = new Uri(Str(cfg, "api_url")).Host; } catch (UriFormatException) { }
            int fail = 0;
            for (int i = 0; i < 10; i++)
            {
                using (TcpClient c = new TcpClient())
                {
                    try
                    {
                        IAsyncResult ar = c.BeginConnect(host, 443, null, null);
                        if (!ar.AsyncWaitHandle.WaitOne(4000) || !c.Connected) fail++;
                    }
                    catch (Exception) { fail++; }
                }
            }
            outLine(string.Format("TCP {0}:443 : {1} echecs sur 10", host, fail));
            for (int i = 1; i <= 3; i++)
            {
                try
                {
                    HttpResult up = SendHttp("GET", "https://" + host + "/up", null, null, ReadAgentVersion(AgentPath));
                    outLine(string.Format("HTTPS /up essai {0} : {1}", i, up.Status));
                }
                catch (Exception ex) { outLine(string.Format("HTTPS /up essai {0} : ERREUR {1}", i, ex.Message)); }
            }

            string report = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.DesktopDirectory), "GescomAgent-diagnostic.txt");
            File.WriteAllLines(report, lines.ToArray(), new UTF8Encoding(true));
            outLine("");
            outLine("Rapport ecrit : " + report);
            return report;
        }

        // Les declencheurs de la tache, comme diagnose.ps1 : montre si la branche a ceux de 1.0.9.
        static string TriggerSummary(object task)
        {
            try
            {
                object triggers = ComGet(ComGet(task, "Definition"), "Triggers");
                int count = Convert.ToInt32(ComGet(triggers, "Count"));
                List<string> parts = new List<string>();
                bool resume = false, endless = false;
                for (int i = 1; i <= count; i++)
                {
                    object t = triggers.GetType().InvokeMember("Item", BindingFlags.GetProperty, null, triggers, new object[] { i });
                    int type = Convert.ToInt32(ComGet(t, "Type"));   // TASK_TRIGGER_TYPE2
                    string name = type == 8 ? "demarrage" : type == 0 ? "evenement" : type == 1 ? "heure" : type == 2 ? "quotidien" : "type " + type;
                    if (type == 0 && Convert.ToString(ComGet(t, "Subscription")).Contains("Power-Troubleshooter"))
                    {
                        name = "retour de veille";
                        resume = true;
                    }
                    object rep = ComGet(t, "Repetition");
                    string interval = Convert.ToString(ComGet(rep, "Interval")), duration = Convert.ToString(ComGet(rep, "Duration"));
                    if (interval.Length > 0)
                    {
                        name += " toutes les " + interval + (duration.Length > 0 ? " pendant " + duration : " sans fin");
                        if (duration.Length == 0) endless = true;
                    }
                    parts.Add(name);
                }
                string s = string.Join(", ", parts.ToArray());
                return resume && endless ? s : s + " -> ANCIENNE TACHE : GescomAgent-Setup.exe > Mettre a jour l'agent";
            }
            catch (Exception ex) { return "illisible (" + Inner(ex).Message + ")"; }
        }

        // Avec le demarrage rapide, "Arreter" met Windows en veille prolongee : le declencheur "retour de veille" relance l'agent.
        static string FastStartup()
        {
            try
            {
                object v = Registry.GetValue(@"HKEY_LOCAL_MACHINE\SYSTEM\CurrentControlSet\Control\Session Manager\Power", "HiberbootEnabled", null);
                if (v == null) return "inconnu";
                return Convert.ToInt32(v) == 1 ? "actif (arreter le PC = veille prolongee)" : "inactif";
            }
            catch (Exception) { return "inconnu"; }
        }

        static string[] ReadShared(string path)
        {
            // le journal est ouvert en ecriture par l'agent
            using (FileStream fs = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
            using (StreamReader r = new StreamReader(fs, Encoding.UTF8))
            {
                return r.ReadToEnd().Replace("\r", "").TrimEnd('\n').Split('\n');
            }
        }
    }
}
