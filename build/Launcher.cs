// ============================================================
//  PointsSystemLauncher.cs  (C# 5 compatible)
//  苍井寿司AI积分管理系统 - 桌面启动器
//  编译: csc.exe /target:winexe /out:Launcher.exe Launcher.cs
// ============================================================
using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Threading;
using System.Windows.Forms;

namespace PointsSystemLauncher
{
    static class Program
    {
        private static Process _phpProcess = null;
        private static NotifyIcon _trayIcon = null;
        private static string _appDir = "";
        private static int _port = 8899;
        private static bool _isExiting = false;
        private static Mutex _mutex = null;
        private static System.Timers.Timer _healthTimer = null;

        [STAThread]
        static void Main(string[] args)
        {
            _appDir = Path.GetDirectoryName(Application.ExecutablePath);
            
            // Parse port from args
            int customPort;
            if (args.Length > 0 && int.TryParse(args[0], out customPort))
            {
                _port = customPort;
            }

            // Ensure single instance
            string mutexName = "PointsSystemLauncher_" + _port.ToString();
            bool createdNew;
            _mutex = new Mutex(true, mutexName, out createdNew);
            if (!createdNew)
            {
                // Already running, just open browser
                OpenBrowser();
                return;
            }

            // Kill any stale PHP process on our port
            KillPhpOnPort(_port);

            // Start PHP server
            if (!StartPhpServer())
            {
                // If port already in use, just open browser
                if (IsPortInUse(_port))
                {
                    OpenBrowser();
                    SetupTrayIcon();
                    Application.Run();
                    return;
                }
                MessageBox.Show("Failed to start PHP server!\nCheck if PHP runtime is complete.",
                    "Startup Failed", MessageBoxButtons.OK, MessageBoxIcon.Error);
                return;
            }

            // Wait for server to be ready
            Thread.Sleep(2000);
            
            // Open browser
            OpenBrowser();

            // Setup system tray icon
            SetupTrayIcon();

            // Start health check timer (ping PHP every 30s)
            StartHealthCheck();

            // Run message loop
            Application.Run();
        }

        private static bool StartPhpServer()
        {
            string phpExe = Path.Combine(_appDir, "php", "php.exe");
            if (!File.Exists(phpExe))
            {
                // Try system PHP
                phpExe = "php.exe";
                try
                {
                    Process p = Process.Start(phpExe, "-v");
                    if (p != null)
                    {
                        p.Kill();
                    }
                }
                catch
                {
                    return false;
                }
            }

            try
            {
                ProcessStartInfo psi = new ProcessStartInfo();
                psi.FileName = phpExe;
                psi.Arguments = string.Format("-S 127.0.0.1:{0} -t \"{1}\"", _port, _appDir);
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.WindowStyle = ProcessWindowStyle.Hidden;
                // Do NOT redirect stdout/stderr — PHP built-in server logs to stderr
                // per-request, and a full buffer would BLOCK PHP, freezing the app.

                _phpProcess = new Process();
                _phpProcess.StartInfo = psi;
                _phpProcess.EnableRaisingEvents = true;
                _phpProcess.Exited += (sender, e) =>
                {
                    if (!_isExiting)
                    {
                        // Auto-restart if crashed
                        Thread.Sleep(1000);
                        if (!_isExiting)
                        {
                            try
                            {
                                _phpProcess.Start();
                            }
                            catch { }
                        }
                    }
                };
                _phpProcess.Start();

                return true;
            }
            catch
            {
                return false;
            }
        }

        private static void SetupTrayIcon()
        {
            _trayIcon = new NotifyIcon();

            try
            {
                _trayIcon.Icon = CreateAppIcon();
            }
            catch
            {
                // Fallback: use default icon
            }

            _trayIcon.Text = string.Format("CangJingSushi Points System\nRunning - http://127.0.0.1:{0}", _port);
            _trayIcon.Visible = true;

            // Context menu
            ContextMenuStrip menu = new ContextMenuStrip();
            
            ToolStripMenuItem openItem = new ToolStripMenuItem("Open Browser");
            openItem.Click += (s, e) => OpenBrowser();
            menu.Items.Add(openItem);

            ToolStripMenuItem restartItem = new ToolStripMenuItem("Restart Server");
            restartItem.Click += (s, e) => RestartServer();
            menu.Items.Add(restartItem);

            menu.Items.Add(new ToolStripSeparator());

            ToolStripMenuItem exitItem = new ToolStripMenuItem("Exit");
            exitItem.Click += (s, e) => ExitApplication();
            menu.Items.Add(exitItem);

            _trayIcon.ContextMenuStrip = menu;

            // Double-click to open browser
            _trayIcon.DoubleClick += (s, e) => OpenBrowser();

            // Handle application exit
            Application.ApplicationExit += (s, e) => Cleanup();
        }

        private static void OpenBrowser()
        {
            string url = string.Format("http://127.0.0.1:{0}/dashboard.php", _port);
            try
            {
                Process.Start(url);
            }
            catch
            {
                // Fallback for older .NET
                try
                {
                    Process.Start("cmd", string.Format("/c start {0}", url));
                }
                catch { }
            }
        }

        private static void RestartServer()
        {
            KillPhpProcess();
            Thread.Sleep(1000);
            StartPhpServer();
            Thread.Sleep(2000);
            OpenBrowser();
        }

        private static void ExitApplication()
        {
            _isExiting = true;
            Cleanup();
            Application.Exit();
        }

        private static void Cleanup()
        {
            // Stop health check timer
            if (_healthTimer != null)
            {
                _healthTimer.Stop();
                _healthTimer.Dispose();
                _healthTimer = null;
            }

            KillPhpProcess();
            
            if (_trayIcon != null)
            {
                _trayIcon.Visible = false;
                _trayIcon.Dispose();
                _trayIcon = null;
            }

            if (_mutex != null)
            {
                _mutex.ReleaseMutex();
                _mutex.Dispose();
                _mutex = null;
            }
        }

        private static void KillPhpProcess()
        {
            if (_phpProcess != null && !_phpProcess.HasExited)
            {
                try { _phpProcess.Kill(); } catch { }
                try { _phpProcess.WaitForExit(3000); } catch { }
                _phpProcess.Dispose();
                _phpProcess = null;
            }
            KillPhpOnPort(_port);
        }

        private static void KillPhpOnPort(int port)
        {
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo();
                psi.FileName = "taskkill";
                psi.Arguments = "/F /IM php.exe";
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.WindowStyle = ProcessWindowStyle.Hidden;
                Process p = Process.Start(psi);
                if (p != null)
                {
                    p.WaitForExit(2000);
                }
            }
            catch { }
        }

        private static bool IsPortInUse(int port)
        {
            try
            {
                TcpListener listener = new TcpListener(IPAddress.Loopback, port);
                listener.Start();
                listener.Stop();
                return false;
            }
            catch
            {
                return true;
            }
        }

        private static void StartHealthCheck()
        {
            _healthTimer = new System.Timers.Timer(30000); // every 30 seconds
            _healthTimer.Elapsed += (sender, e) =>
            {
                if (_isExiting) return;

                bool alive = false;
                try
                {
                    System.Net.HttpWebRequest req = (System.Net.HttpWebRequest)System.Net.WebRequest.Create(
                        string.Format("http://127.0.0.1:{0}/dashboard.php", _port));
                    req.Method = "HEAD";
                    req.Timeout = 5000;
                    using (var resp = (System.Net.HttpWebResponse)req.GetResponse())
                    {
                        alive = (resp.StatusCode == System.Net.HttpStatusCode.OK);
                    }
                }
                catch { }

                if (!alive)
                {
                    // PHP is down, try to restart
                    KillPhpProcess();
                    Thread.Sleep(1000);
                    if (!_isExiting)
                    {
                        StartPhpServer();
                        // Show balloon notification
                        if (_trayIcon != null)
                        {
                            _trayIcon.ShowBalloonTip(3000, "Server Restarted",
                                "PHP server was down and has been restarted.",
                                ToolTipIcon.Info);
                        }
                    }
                }
            };
            _healthTimer.AutoReset = true;
            _healthTimer.Start();
        }

        private static Icon CreateAppIcon()
        {
            // Create a beautiful icon matching the logo design
            // Use a larger size for better quality on high-DPI displays
            Bitmap bmp = new Bitmap(64, 64);
            Graphics g = Graphics.FromImage(bmp);
            g.SmoothingMode = System.Drawing.Drawing2D.SmoothingMode.HighQuality;
            g.Clear(Color.Transparent);

            // Gold outer ring
            using (Pen goldPen = new Pen(Color.FromArgb(255, 200, 150, 50), 3))
            {
                g.DrawEllipse(goldPen, 2, 2, 58, 58);
            }

            // Red background circle
            using (Brush redBrush = new SolidBrush(Color.FromArgb(255, 180, 30, 30)))
            {
                g.FillEllipse(redBrush, 5, 5, 52, 52);
            }

            // Rice bowl (white ellipse)
            using (Brush riceBrush = new SolidBrush(Color.FromArgb(255, 245, 245, 245)))
            {
                g.FillEllipse(riceBrush, 12, 28, 38, 28);
            }
            using (Pen bowlPen = new Pen(Color.FromArgb(255, 220, 220, 220), 1))
            {
                g.DrawEllipse(bowlPen, 12, 28, 38, 28);
            }

            // Salmon slice (pink)
            using (Brush salmonBrush = new SolidBrush(Color.FromArgb(220, 230, 130, 145)))
            {
                g.FillEllipse(salmonBrush, 18, 32, 26, 16);
            }

            // Gold star on top
            PointF[] star = new PointF[10];
            double cx = 31, cy = 18;
            for (int i = 0; i < 10; i++)
            {
                double angle = Math.PI * (i * 36 - 90) / 180;
                double r = (i % 2 == 0) ? 12 : 5;
                star[i] = new PointF((float)(cx + r * Math.Cos(angle)), (float)(cy + r * Math.Sin(angle)));
            }
            using (Brush starBrush = new SolidBrush(Color.FromArgb(255, 255, 200, 50)))
            {
                g.FillPolygon(starBrush, star);
            }
            using (Pen starPen = new Pen(Color.FromArgb(255, 220, 160, 20), 1))
            {
                g.DrawPolygon(starPen, star);
            }

            // Small highlight on star
            using (Brush hlBrush = new SolidBrush(Color.FromArgb(100, 255, 255, 200)))
            {
                g.FillEllipse(hlBrush, 26, 13, 10, 6);
            }

            g.Dispose();

            // Create icon from bitmap
            IntPtr hIcon = bmp.GetHicon();
            Icon icon = Icon.FromHandle(hIcon);
            bmp.Dispose();
            return icon;
        }
    }
}
