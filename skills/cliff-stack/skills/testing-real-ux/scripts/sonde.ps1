# Run by ux.mjs on Windows: every new process (Toolhelp snapshot, a few ms) and every change of the foreground window's
# owner, sampled every <ms> ms, on stdout: « P <pid> <parent> <birth FILETIME UTC> », « F <pid> ». « PRET » once running.
# -une: « MOI <pid> », then every process once, then it exits.
param([int]$ms = 100, [switch]$une)
Add-Type -TypeDefinition @'
using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Threading;
public static class SondeUx {
  [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
  struct PE {
    public uint dwSize; public uint cntUsage; public uint th32ProcessID; public IntPtr th32DefaultHeapID; public uint th32ModuleID;
    public uint cntThreads; public uint th32ParentProcessID; public int pcPriClassBase; public uint dwFlags;
    [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 260)] public string szExeFile;
  }
  [DllImport("kernel32.dll")] static extern IntPtr CreateToolhelp32Snapshot(uint flags, uint pid);
  [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] static extern bool Process32FirstW(IntPtr h, ref PE e);
  [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] static extern bool Process32NextW(IntPtr h, ref PE e);
  [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr h);
  [DllImport("kernel32.dll")] static extern IntPtr OpenProcess(uint access, bool inherit, uint pid);
  [DllImport("kernel32.dll")] static extern bool GetProcessTimes(IntPtr h, out long creation, out long exit, out long kernel, out long user);
  [DllImport("user32.dll")] static extern IntPtr GetForegroundWindow();
  [DllImport("user32.dll")] static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
  static long Naissance(uint pid) {
    IntPtr h = OpenProcess(0x1000, false, pid);  // PROCESS_QUERY_LIMITED_INFORMATION
    if (h == IntPtr.Zero) return 0;
    long c, e, k, u;
    bool ok = GetProcessTimes(h, out c, out e, out k, out u);
    CloseHandle(h);
    return ok ? c : 0;
  }
  public static void Boucle(int ms, bool une) {
    var connus = new Dictionary<uint, uint>();
    uint avant = uint.MaxValue;
    Console.Out.WriteLine(une ? "MOI " + System.Diagnostics.Process.GetCurrentProcess().Id : "PRET");
    Console.Out.Flush();
    while (true) {
      IntPtr s = CreateToolhelp32Snapshot(2, 0);  // TH32CS_SNAPPROCESS
      if (s != new IntPtr(-1)) {
        var e = new PE();
        e.dwSize = (uint)Marshal.SizeOf(typeof(PE));
        var vivants = new HashSet<uint>();
        for (bool ok = Process32FirstW(s, ref e); ok; ok = Process32NextW(s, ref e)) {
          uint pid = e.th32ProcessID, parent = e.th32ParentProcessID, deja;
          vivants.Add(pid);
          if (connus.TryGetValue(pid, out deja) && deja == parent) continue;
          connus[pid] = parent;
          long n = Naissance(pid);
          if (n != 0) Console.Out.WriteLine("P " + pid + " " + parent + " " + n);
        }
        CloseHandle(s);
        var morts = new List<uint>();
        foreach (var k in connus.Keys) if (!vivants.Contains(k)) morts.Add(k);
        foreach (var k in morts) connus.Remove(k);
      }
      if (une) { Console.Out.Flush(); return; }
      uint f;
      GetWindowThreadProcessId(GetForegroundWindow(), out f);
      if (f != avant) { avant = f; Console.Out.WriteLine("F " + f); }
      Console.Out.Flush();
      Thread.Sleep(ms);
    }
  }
}
'@
[SondeUx]::Boucle($ms, [bool]$une)
