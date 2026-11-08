// Normalize PowerShell's text pipeline before sending a shell script over SSH.
const fs = require('node:fs');
const { spawnSync } = require('node:child_process');
const result = spawnSync('ssh.exe', process.argv.slice(2), {
  input: fs.readFileSync(0, 'utf8').replace(/\r/g, '') + '\n',
  stdio: ['pipe', 'inherit', 'inherit'], windowsHide: true,
});
if (result.error) { console.error(result.error.message); }
process.exitCode = result.status ?? 1;
