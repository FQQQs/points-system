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
using System.Text;
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
                psi.Arguments = string.Format("-S localhost:{0} -t \"{1}\"", _port, _appDir);
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.WindowStyle = ProcessWindowStyle.Hidden;
                psi.RedirectStandardOutput = true;
                psi.RedirectStandardError = true;
                psi.StandardOutputEncoding = Encoding.UTF8;
                psi.StandardErrorEncoding = Encoding.UTF8;

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

            _trayIcon.Text = string.Format("CangJingSushi Points System\nRunning - http://localhost:{0}", _port);
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
            string url = string.Format("http://localhost:{0}/dashboard.php", _port);
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

        private static Icon CreateAppIcon()
        {
            // Create a simple icon (green dot)
            Bitmap bmp = new Bitmap(16, 16);
            Graphics g = Graphics.FromImage(bmp);
            g.Clear(Color.Transparent);
            Brush brush = new SolidBrush(Color.FromArgb(0, 153, 76));
            g.FillEllipse(brush, 1, 1, 14, 14);
            g.Dispose();
            brush.Dispose();
            IntPtr hIcon = bmp.GetHicon();
            Icon icon = Icon.FromHandle(hIcon);
            bmp.Dispose();
            return icon;
        }
    }
}
