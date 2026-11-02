// Fenetre de GescomAgent-Setup.exe. Le travail (detection, installation...) est fait par Installer, hors du fil de l'interface.
using System;
using System.Collections.Generic;
using System.Drawing;
using System.IO;
using System.Text.RegularExpressions;
using System.Threading;
using System.Windows.Forms;

namespace GescomAgentSetup
{
    class SetupForm : Form
    {
        static readonly Color OkColor = Color.FromArgb(26, 127, 55);
        static readonly Color BadColor = Color.FromArgb(200, 35, 35);
        static readonly Color WarnColor = Color.FromArgb(154, 103, 0);
        static readonly Color MutedColor = Color.FromArgb(96, 102, 112);

        readonly Options opt;
        readonly string packageDir;
        readonly string packageVersion;
        readonly string blocked;            // err_files : on n'installe rien depuis cette fenetre
        string lang;
        Detection found;
        bool busy;
        string statusKey;
        object[] statusArgs = new object[0];
        Color statusColor = MutedColor;

        Label lblTitle, lblSubtitle, lblDbKey, lblDbVal, lblIsqlKey, lblIsqlVal, lblSvcKey, lblSvcVal, lblWarn;
        Label lblName, lblCode, lblCodeHint, lblPass, lblApi, lblDb, lblInstalledHead, lblInstalledInfo, lblStatus, lblLog;
        GroupBox grpFound;
        Panel pnlInstall, pnlAdvanced, pnlInstalled;
        TextBox txtName, txtCode, txtPass, txtApi, txtDb, txtLog;
        LinkLabel lnkAdvanced;
        Button btnAr, btnFr, btnEn, btnBrowse, btnInstall, btnUpdate, btnDiagnose, btnUninstall, btnClose;
        ProgressBar progress;

        public SetupForm(Options options)
        {
            opt = options;
            lang = options.Lang;
            packageDir = AppDomain.CurrentDomain.BaseDirectory;
            string agent = Path.Combine(packageDir, "agent.ps1");
            // exe ouvert depuis le zip sans l'extraire : Windows le copie seul dans Temp, sans agent.ps1
            if (!File.Exists(agent)) blocked = "err_files";
            packageVersion = Installer.ReadAgentVersion(agent);
            BuildLayout();
            ApplyTexts();
            Shown += delegate
            {
                if (blocked != null) SetStatus(blocked, BadColor);
                else RunDetect();
                txtName.Focus();
            };
            FormClosing += delegate(object s, FormClosingEventArgs e)
            {
                if (!busy) return;
                e.Cancel = true;
                MessageBox.Show(this, T("busy"), T("window_title"), MessageBoxButtons.OK, MessageBoxIcon.Information,
                    MessageBoxDefaultButton.Button1, MsgOptions());
            };
        }

        string T(string key, params object[] args) { return Texts.Get(lang, key, args); }

        // ---------- mise en page ----------

        // Positions dessinees a 96 ppp, de gauche a droite. DoLayout les met a l'echelle de l'ecran et les inverse
        // en arabe : RightToLeftLayout ne retourne que les enfants directs de la fenetre, pas ceux des panneaux.
        class Placed
        {
            public Control Control;
            public Rectangle Design;
            public int ParentWidth;
        }

        const int DesignWidth = 640, DesignHeight = 600;
        readonly List<Placed> placed = new List<Placed>();
        readonly Dictionary<Control, int> designWidths = new Dictionary<Control, int>();
        float scale = 1F;

        TControl Put<TControl>(Control parent, TControl c, string name, int x, int y, int w, int h) where TControl : Control
        {
            c.Name = name;
            parent.Controls.Add(c);
            Placed p = new Placed();
            p.Control = c;
            p.Design = new Rectangle(x, y, w, h);
            p.ParentWidth = designWidths[parent];
            placed.Add(p);
            designWidths[c] = w;
            return c;
        }

        int Scaled(int v) { return (int)Math.Round(v * scale); }

        void DoLayout()
        {
            bool rtl = lang == "ar";
            SuspendLayout();
            foreach (Placed p in placed)
            {
                int x = rtl ? p.ParentWidth - p.Design.Right : p.Design.X;
                p.Control.SetBounds(Scaled(x), Scaled(p.Design.Y), Scaled(p.Design.Width), Scaled(p.Design.Height));
            }
            ResumeLayout();
        }

        void BuildLayout()
        {
            SuspendLayout();
            // les polices (en points) suivent deja le DPI de l'ecran ; les positions sont mises a l'echelle par DoLayout
            AutoScaleMode = AutoScaleMode.None;
            using (Graphics g = CreateGraphics()) scale = g.DpiX / 96F;
            designWidths[this] = DesignWidth;
            Font = new Font("Segoe UI", 9.75F);
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = false;
            StartPosition = FormStartPosition.CenterScreen;
            ClientSize = new Size(Scaled(DesignWidth), Scaled(DesignHeight));
            Font small = new Font("Segoe UI", 8.75F);

            lblTitle = Put(this, new Label(), "lblTitle", 20, 12, 440, 30);
            lblTitle.Font = new Font("Segoe UI", 14F, FontStyle.Bold);
            lblSubtitle = Put(this, new Label(), "lblSubtitle", 20, 44, 600, 20);
            lblSubtitle.ForeColor = MutedColor;
            btnAr = Put(this, new Button(), "btnLangAr", 474, 12, 46, 26);
            btnFr = Put(this, new Button(), "btnLangFr", 524, 12, 46, 26);
            btnEn = Put(this, new Button(), "btnLangEn", 574, 12, 46, 26);
            btnAr.Text = "عربي";
            btnFr.Text = "FR";
            btnEn.Text = "EN";
            btnAr.Click += delegate { SetLang("ar"); };
            btnFr.Click += delegate { SetLang("fr"); };
            btnEn.Click += delegate { SetLang("en"); };

            grpFound = Put(this, new GroupBox(), "grpFound", 20, 68, 600, 118);
            lblDbKey = Put(grpFound, new Label(), "lblDbKey", 14, 20, 150, 20);
            lblDbVal = Put(grpFound, new Label(), "lblDbVal", 168, 20, 420, 20);
            lblIsqlKey = Put(grpFound, new Label(), "lblIsqlKey", 14, 42, 150, 20);
            lblIsqlVal = Put(grpFound, new Label(), "lblIsqlVal", 168, 42, 420, 20);
            lblSvcKey = Put(grpFound, new Label(), "lblSvcKey", 14, 64, 150, 20);
            lblSvcVal = Put(grpFound, new Label(), "lblSvcVal", 168, 64, 420, 20);
            lblWarn = Put(grpFound, new Label(), "lblWarn", 14, 86, 574, 28);
            lblWarn.Font = small;
            lblWarn.ForeColor = WarnColor;
            // chemins et versions : toujours de gauche a droite
            foreach (Label l in new[] { lblDbVal, lblIsqlVal, lblSvcVal }) { l.RightToLeft = RightToLeft.No; l.AutoEllipsis = true; }

            pnlInstall = Put(this, new Panel(), "pnlInstall", 20, 192, 600, 200);
            lblName = Put(pnlInstall, new Label(), "lblName", 0, 4, 160, 22);
            txtName = Put(pnlInstall, new TextBox(), "txtName", 166, 1, 300, 24);
            txtName.MaxLength = 100;
            lblCode = Put(pnlInstall, new Label(), "lblCode", 0, 36, 160, 22);
            txtCode = Put(pnlInstall, new TextBox(), "txtCode", 166, 33, 300, 24);
            txtCode.MaxLength = 50;
            txtCode.CharacterCasing = CharacterCasing.Upper;
            txtCode.RightToLeft = RightToLeft.No;
            txtCode.Font = new Font("Consolas", 11F);
            lblCodeHint = Put(pnlInstall, new Label(), "lblCodeHint", 166, 60, 434, 18);
            lblCodeHint.Font = small;
            lblCodeHint.ForeColor = MutedColor;
            lblPass = Put(pnlInstall, new Label(), "lblPass", 0, 84, 160, 22);
            txtPass = Put(pnlInstall, new TextBox(), "txtPass", 166, 81, 300, 24);
            txtPass.UseSystemPasswordChar = true;
            txtPass.RightToLeft = RightToLeft.No;
            lblPass.Visible = txtPass.Visible = false;
            lnkAdvanced = Put(pnlInstall, new LinkLabel(), "lnkAdvanced", 0, 114, 300, 20);
            lnkAdvanced.LinkClicked += delegate { ShowAdvanced(!pnlAdvanced.Visible); };

            pnlAdvanced = Put(pnlInstall, new Panel(), "pnlAdvanced", 0, 136, 600, 64);
            pnlAdvanced.Visible = false;
            lblApi = Put(pnlAdvanced, new Label(), "lblApi", 0, 4, 160, 22);
            txtApi = Put(pnlAdvanced, new TextBox(), "txtApi", 166, 1, 434, 24);
            txtApi.RightToLeft = RightToLeft.No;
            lblDb = Put(pnlAdvanced, new Label(), "lblDb", 0, 36, 160, 22);
            txtDb = Put(pnlAdvanced, new TextBox(), "txtDb", 166, 33, 394, 24);
            txtDb.RightToLeft = RightToLeft.No;
            btnBrowse = Put(pnlAdvanced, new Button(), "btnBrowse", 566, 32, 34, 26);
            btnBrowse.Text = "…";
            btnBrowse.Click += delegate { BrowseDb(); };

            pnlInstalled = Put(this, new Panel(), "pnlInstalled", 20, 192, 600, 200);
            pnlInstalled.Visible = false;
            lblInstalledHead = Put(pnlInstalled, new Label(), "lblInstalledHead", 0, 4, 600, 24);
            lblInstalledHead.Font = new Font("Segoe UI", 10.5F, FontStyle.Bold);
            lblInstalledHead.ForeColor = OkColor;
            lblInstalledInfo = Put(pnlInstalled, new Label(), "lblInstalledInfo", 0, 32, 600, 72);
            btnUpdate = Put(pnlInstalled, new Button(), "btnUpdate", 0, 112, 190, 34);
            btnDiagnose = Put(pnlInstalled, new Button(), "btnDiagnose", 200, 112, 140, 34);
            btnUninstall = Put(pnlInstalled, new Button(), "btnUninstall", 350, 112, 140, 34);
            btnUpdate.Click += delegate { OnUpdate(); };
            btnDiagnose.Click += delegate { OnDiagnose(); };
            btnUninstall.Click += delegate { OnUninstall(); };

            progress = Put(this, new ProgressBar(), "progress", 20, 398, 600, 8);
            progress.Maximum = 6;
            lblStatus = Put(this, new Label(), "lblStatus", 20, 410, 600, 38);
            lblStatus.Font = new Font("Segoe UI", 9.75F, FontStyle.Bold);
            lblLog = Put(this, new Label(), "lblLog", 20, 450, 600, 18);
            lblLog.ForeColor = MutedColor;
            txtLog = Put(this, new TextBox(), "txtLog", 20, 468, 600, 80);
            txtLog.Multiline = true;
            txtLog.ReadOnly = true;
            txtLog.ScrollBars = ScrollBars.Vertical;
            txtLog.BackColor = SystemColors.Window;
            txtLog.RightToLeft = RightToLeft.No;
            txtLog.Font = new Font("Consolas", 9F);

            btnInstall = Put(this, new Button(), "btnInstall", 20, 556, 170, 32);
            btnInstall.Font = new Font("Segoe UI", 10F, FontStyle.Bold);
            btnInstall.Click += delegate { OnInstall(); };
            btnClose = Put(this, new Button(), "btnClose", 500, 556, 120, 32);
            btnClose.Click += delegate { Close(); };
            AcceptButton = btnInstall;
            ResumeLayout(false);
            PerformLayout();
        }

        void SetLang(string l)
        {
            lang = l;
            ApplyTexts();
        }

        void ApplyTexts()
        {
            RightToLeft = lang == "ar" ? RightToLeft.Yes : RightToLeft.No;
            DoLayout();
            Text = T("window_title");
            lblTitle.Text = T("title");
            lblSubtitle.Text = T("subtitle", packageVersion ?? "?");
            foreach (Button b in new[] { btnAr, btnFr, btnEn })
            {
                b.Font = new Font(Font, b == LangButton() ? FontStyle.Bold : FontStyle.Regular);
            }
            grpFound.Text = T("found");
            lblDbKey.Text = T("row_db");
            lblIsqlKey.Text = T("row_isql");
            lblSvcKey.Text = T("row_service");
            // nom accessible des champs = leur libelle (lecteurs d'ecran, et tests UI Automation)
            txtName.AccessibleName = lblName.Text = T("branch_name");
            txtCode.AccessibleName = lblCode.Text = T("install_code");
            lblCodeHint.Text = T("code_hint");
            txtPass.AccessibleName = lblPass.Text = T("db_password");
            lnkAdvanced.Text = T(pnlAdvanced.Visible ? "advanced_hide" : "advanced_show");
            txtApi.AccessibleName = lblApi.Text = T("api_url");
            txtDb.AccessibleName = lblDb.Text = T("db_file");
            txtLog.AccessibleName = lblLog.Text = T("details");
            lblInstalledHead.Text = T("installed_head");
            btnUpdate.Text = T("update");
            btnDiagnose.Text = T("diagnose");
            btnUninstall.Text = T("uninstall");
            btnInstall.Text = T("install");
            btnClose.Text = T("close");
            RenderFound();
            RenderStatus();
        }

        Button LangButton() { return lang == "fr" ? btnFr : lang == "en" ? btnEn : btnAr; }

        MessageBoxOptions MsgOptions()
        {
            return lang == "ar" ? MessageBoxOptions.RtlReading | MessageBoxOptions.RightAlign : 0;
        }

        // ---------- ce que l'installateur a trouve ----------

        bool IsRegistered() { return found != null && found.Installed != null && found.Installed.Registered; }

        static void SetRow(Label l, bool ok, string text)
        {
            l.Text = (ok ? "✓  " : "✗  ") + text;
            l.ForeColor = ok ? OkColor : BadColor;
        }

        void RenderFound()
        {
            if (found == null)
            {
                lblDbVal.Text = blocked == "err_files" ? "—" : T("searching");
                lblDbVal.ForeColor = MutedColor;
                lblIsqlVal.Text = lblSvcVal.Text = lblWarn.Text = "";
            }
            else
            {
                string db = found.Database;
                // ‎ : la date reste 2026-11-02 apres un mot arabe (sinon l'algorithme bidi l'affiche 02-11-2026)
                SetRow(lblDbVal, db != null, db != null
                    ? db + "   (" + T("last_write", "‎" + found.DatabaseTime.ToString("yyyy-MM-dd HH:mm")) + ")"
                    : T("not_found"));
                SetRow(lblIsqlVal, found.Isql != null, found.Isql ?? T("not_found"));
                SetRow(lblSvcVal, found.InterBaseRunning, T(found.InterBaseRunning ? "running" : "stopped"));
                string warn = "";
                if (db == null) warn = found.GescommDir != null ? T("client_pc", found.GescommDir) : T("no_gescomm");
                else if (found.NewerDatabases.Count > 0)
                {
                    warn = T("newer_db", Path.GetFileName(db), string.Join(", ", found.NewerDatabases.ToArray()));
                }
                lblWarn.Text = warn;
            }
            bool registered = IsRegistered();
            pnlInstalled.Visible = registered;
            pnlInstall.Visible = btnInstall.Visible = !registered;
            if (registered)
            {
                InstalledAgent i = found.Installed;
                lblInstalledInfo.Text = T("installed_info", i.BranchName, i.Version ?? "?", packageVersion ?? "?", i.Database);
            }
            UpdateEnabled();
        }

        void UpdateEnabled()
        {
            bool canInstall = !busy && blocked == null && found != null;
            foreach (Control c in new Control[] { txtName, txtCode, txtPass, txtApi, txtDb, btnBrowse, lnkAdvanced, btnInstall })
            {
                c.Enabled = canInstall;
            }
            btnUpdate.Enabled = btnUninstall.Enabled = !busy && blocked == null;
            btnDiagnose.Enabled = !busy;
            btnClose.Enabled = !busy;
            UseWaitCursor = busy;
        }

        void SetBusy(bool value)
        {
            busy = value;
            UpdateEnabled();
        }

        // ---------- statut ----------

        void SetStatus(string key, Color color, params object[] args)
        {
            statusKey = key;
            statusArgs = args;
            statusColor = color;
            RenderStatus();
        }

        void RenderStatus()
        {
            if (statusKey == null) { lblStatus.Text = ""; return; }
            if (statusKey == "step")
            {
                int n = (int)statusArgs[0];
                lblStatus.Text = T("step", n, T("step_" + n));
            }
            else
            {
                lblStatus.Text = T(statusKey, statusArgs);
            }
            lblStatus.ForeColor = statusColor;
        }

        void AppendLog(string line)
        {
            txtLog.AppendText(line + Environment.NewLine);
            // "== 3/6 Test de la base" : etape de l'installation
            Match m = Regex.Match(line, @"^== (\d)/6\b");
            if (m.Success)
            {
                int n = int.Parse(m.Groups[1].Value);
                progress.Value = n - 1;
                SetStatus("step", MutedColor, n);
            }
        }

        // ---------- travail en arriere-plan ----------

        void OnUi(MethodInvoker action)
        {
            try
            {
                if (!IsDisposed && IsHandleCreated) BeginInvoke(action);
            }
            catch (InvalidOperationException) { }   // fenetre fermee entre-temps
        }

        Installer NewInstaller(bool showLog)
        {
            Action<string> logLine = null;
            if (showLog) logLine = delegate(string line) { OnUi(delegate { AppendLog(line); }); };
            return new Installer(opt.InstallDir, opt.TaskName, packageDir, logLine);
        }

        void RunOp(Action<Installer> work, Action<Exception> done)
        {
            txtLog.Clear();
            SetStatus("working", MutedColor);
            SetBusy(true);
            Installer inst = NewInstaller(true);
            ThreadPool.QueueUserWorkItem(delegate
            {
                Exception error = null;
                try { work(inst); }
                catch (Exception ex) { error = ex; }
                OnUi(delegate
                {
                    SetBusy(false);
                    if (error != null) ShowFailure(error);
                    else done(null);
                });
            });
        }

        void RunDetect()
        {
            Installer inst = NewInstaller(false);
            ThreadPool.QueueUserWorkItem(delegate
            {
                Detection d = null;
                Exception error = null;
                try { d = inst.Detect(); }
                catch (Exception ex) { error = ex; }
                OnUi(delegate
                {
                    if (d == null)
                    {
                        txtLog.AppendText(error.ToString());
                        SetStatus("err_detect", BadColor);
                        return;
                    }
                    found = d;
                    if (txtApi.Text.Length == 0) txtApi.Text = opt.ApiUrl ?? Installer.DefaultApiUrl;
                    if (txtDb.Text.Length == 0 && d.Database != null) txtDb.Text = d.Database;
                    RenderFound();
                });
            });
        }

        // ---------- actions ----------

        void ShowAdvanced(bool show)
        {
            pnlAdvanced.Visible = show;
            lnkAdvanced.Text = T(show ? "advanced_hide" : "advanced_show");
        }

        void BrowseDb()
        {
            using (OpenFileDialog d = new OpenFileDialog())
            {
                d.Title = T("choose_db");
                d.Filter = "InterBase (*.ib)|*.ib|*.*|*.*";
                string current = txtDb.Text.Trim();
                string dir = current.Length > 0 ? Path.GetDirectoryName(current) : found == null ? null : found.GescommDir;
                if (!string.IsNullOrEmpty(dir) && Directory.Exists(dir)) d.InitialDirectory = dir;
                if (d.ShowDialog(this) == DialogResult.OK) txtDb.Text = d.FileName;
            }
        }

        void OnInstall()
        {
            string name = txtName.Text.Trim();
            string code = txtCode.Text.Trim();
            string api = txtApi.Text.Trim();
            if (name.Length == 0 || code.Length == 0)
            {
                SetStatus("err_fields", BadColor);
                (name.Length == 0 ? txtName : txtCode).Focus();
                return;
            }
            string db = txtDb.Text.Trim();
            string password = txtPass.Visible ? txtPass.Text : null;
            progress.Value = 0;
            RunOp(delegate(Installer i) { i.Install(api, name, code, db, password); }, delegate
            {
                progress.Value = progress.Maximum;
                SetStatus("ok_installed", OkColor);
                RunDetect();
            });
        }

        public void ShowCrash(string text)
        {
            OnUi(delegate
            {
                SetBusy(false);
                AppendLog(text);
                SetStatus("err_generic", BadColor, "?");
            });
        }

        void ShowFailure(Exception error)
        {
            SetupException se = error as SetupException;
            AppendLog("ERREUR : " + error.Message);
            if (se == null)
            {
                SetStatus("err_generic", BadColor, error.GetType().Name);
                return;
            }
            switch (se.Code)
            {
                case 10: SetStatus("err_admin", BadColor); break;
                case 11: SetStatus("err_files", BadColor); break;
                case 12: SetStatus("err_installed", BadColor); RunDetect(); break;
                case 13: SetStatus("err_not_installed", BadColor); RunDetect(); break;
                case 20:
                    if (se.Message.Contains("https://"))
                    {
                        ShowAdvanced(true);
                        SetStatus("err_api", BadColor);
                        txtApi.Focus();
                    }
                    else SetStatus("err_fields", BadColor);
                    break;
                case 21: SetStatus("err_isql", BadColor); break;
                case 22:
                    ShowAdvanced(true);
                    SetStatus("err_db", BadColor);
                    txtDb.Focus();
                    break;
                case 30:
                    lblPass.Visible = txtPass.Visible = true;
                    SetStatus("err_db_test", BadColor);
                    txtPass.Focus();
                    break;
                case 40:
                    // reponse 422 = code refuse ; sans reponse HTTP = reseau
                    if (se.Message.StartsWith("HTTP 422"))
                    {
                        SetStatus("err_code", BadColor);
                        txtCode.Focus();
                        txtCode.SelectAll();
                    }
                    else SetStatus("err_network", BadColor);
                    break;
                case 50: SetStatus("err_full_test", BadColor); break;
                case 60: SetStatus("err_task", BadColor); break;
                default: SetStatus("err_generic", BadColor, se.Code); break;
            }
        }

        void OnUpdate()
        {
            RunOp(delegate(Installer i) { i.Update(); }, delegate
            {
                SetStatus("ok_updated", OkColor);
                RunDetect();
            });
        }

        void OnDiagnose()
        {
            string report = null;
            RunOp(delegate(Installer i) { report = i.Diagnose(); }, delegate { SetStatus("ok_diagnose", OkColor, report); });
        }

        void OnUninstall()
        {
            DialogResult answer = MessageBox.Show(this, T("confirm_uninstall"), T("window_title"), MessageBoxButtons.YesNo,
                MessageBoxIcon.Warning, MessageBoxDefaultButton.Button2, MsgOptions());
            if (answer != DialogResult.Yes) return;
            RunOp(delegate(Installer i) { i.Uninstall(); }, delegate
            {
                txtName.Clear();
                txtCode.Clear();
                progress.Value = 0;
                SetStatus("ok_uninstalled", OkColor);
                RunDetect();
            });
        }
    }
}
