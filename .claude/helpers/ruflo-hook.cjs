#!/usr/bin/env node
/**
 * ruflo-hook.cjs — cross-platform Node.js port of ruflo-hook.sh (#2132)
 *
 * Deployed to .claude/helpers/ during ruflo init. On Windows, the
 * generated .claude/settings.json hooks point here instead of the
 * plugin's bash-only ruflo-hook.sh.
 *
 * Always exits 0 — hook subcommands are best-effort telemetry and must
 * never block a Claude Code turn.
 *
 * Windows argv integrity: hook-derived values (a Bash tool's `command`, a file path) must
 * reach the CLI as literal argv and never as shell syntax. Two layers, in
 * this order:
 *
 *   1. resolveInvocation() maps the command to a real executable — and on
 *      Windows maps npm's .cmd shim to the package's own .js entrypoint —
 *      so the spawn runs `node <entry>` with shell:false and NO cmd.exe in
 *      the chain at all. Nothing to escape, nothing to re-tokenize, and no
 *      %VAR% expansion. (The escaped fallback was measured on a real
 *      windows-latest runner and does pass %VAR% through literally; layer 1
 *      is still preferred because it removes the parser rather than
 *      out-guessing it.)
 *   2. escapeCmdArg() guards the residual Windows path where step 1 cannot
 *      identify an entrypoint. Escaping is strictly the weaker layer: it is
 *      only reachable when resolution fails, and it is the one part of this
 *      file that a non-Windows CI run cannot prove.
 */

'use strict';

const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

function done() { process.exit(0); }

/** Case-insensitive env lookup — Windows env keys are not case-stable. */
function envValue(env, name) {
  const key = Object.keys(env).find((c) => c.toLowerCase() === name.toLowerCase());
  return key ? env[key] : undefined;
}

/**
 * Locate a command on PATH using fs only.
 *
 * Deliberately NOT `execSync('where ...')` / `command -v`: that spawns a
 * shell on every hook invocation, which is both the thing this file is
 * trying to get away from and a per-turn cost. Taking `env` and `platform`
 * as arguments is what lets the Windows branch be exercised from a
 * Linux/macOS CI run — see the Windows argv tests.
 */
function resolveCommandPath(command, env = process.env, platform = process.platform) {
  const hasSeparator = command.includes('/') || command.includes('\\');
  const dirs = hasSeparator
    ? ['']
    : (envValue(env, 'PATH') || '').split(platform === 'win32' ? ';' : path.delimiter);
  const hasExtension = path.extname(command) !== '';
  const extensions = platform === 'win32' && !(hasSeparator && hasExtension)
    ? (envValue(env, 'PATHEXT') || '.COM;.EXE;.BAT;.CMD').split(';')
    : [''];
  for (const dir of dirs) {
    // A relative or empty PATH entry resolves against the cwd, i.e. the
    // opened project; never resolve the CLI from there.
    if (!hasSeparator && !(platform === 'win32' ? path.win32 : path.posix).isAbsolute(dir)) continue;
    for (const ext of extensions) {
      const base = path.resolve(dir || '.', command);
      const candidates = ext
        ? [base + ext.toLowerCase(), base + ext.toUpperCase()]
        : [base];
      for (const file of candidates) {
        try {
          fs.accessSync(file, platform === 'win32' ? fs.constants.F_OK : fs.constants.X_OK);
          if (fs.statSync(file).isFile()) return file;
        } catch { /* keep searching */ }
      }
    }
  }
  return null;
}

/**
 * Map an npm-generated Windows shim (ruflo.cmd / npx.cmd / …) to the .js
 * entrypoint it would have run, so it can be executed as `node <entry>`
 * with no shell.
 *
 * Handles both npm layouts: a global prefix (`<prefix>/ruflo.cmd` beside
 * `<prefix>/node_modules/ruflo`) and a local one (`node_modules/.bin/ruflo.cmd`
 * beside `node_modules/ruflo`). `npx` lives in the `npm` package, hence the
 * command→package mapping rather than assuming they match.
 *
 * The entrypoint comes from the package's own `bin` field, never a guessed
 * filename, and is required to resolve inside the package directory — a
 * manifest pointing outside it is refused rather than followed.
 */
function resolveNpmShim(shimPath) {
  const command = path.basename(shimPath, path.extname(shimPath)).toLowerCase();
  const packageName = command === 'npx' ? 'npm' : command;
  if (!['ruflo', 'claude-flow', 'npm'].includes(packageName)) return null;
  try {
    const shimDir = path.dirname(shimPath);
    const packageDir = path.basename(shimDir).toLowerCase() === '.bin'
      ? path.resolve(shimDir, '..', packageName)
      : path.resolve(shimDir, 'node_modules', packageName);
    const manifest = JSON.parse(fs.readFileSync(path.join(packageDir, 'package.json'), 'utf8'));
    const declared = typeof manifest.bin === 'string' ? manifest.bin : manifest.bin?.[command];
    if (typeof declared !== 'string') return null;
    const canonicalPackageDir = fs.realpathSync(packageDir);
    const canonicalEntry = fs.realpathSync(path.resolve(packageDir, declared));
    const relativeEntry = path.relative(canonicalPackageDir, canonicalEntry);
    if (relativeEntry.startsWith('..' + path.sep) || path.isAbsolute(relativeEntry)) return null;
    if (!fs.statSync(canonicalEntry).isFile()) return null;
    return { command: process.execPath, args: [canonicalEntry] };
  } catch { return null; }
}

/**
 * Decide how to run `bin` without a shell. Returns {command, args}, or null
 * when no shell-free invocation could be identified (Windows shim that is
 * not an npm package entry) — the caller then falls back to the escaped
 * cmd.exe path rather than dropping the hook.
 */
function resolveInvocation(bin, binArgs, options = {}) {
  const env = options.env || process.env;
  const platform = options.platform || process.platform;
  const commandPath = resolveCommandPath(bin, env, platform);
  if (!commandPath) return null;
  if (platform === 'win32' && /\.(?:cmd|bat|ps1)$/i.test(commandPath)) {
    const npmBin = resolveNpmShim(commandPath);
    return npmBin ? { command: npmBin.command, args: [...npmBin.args, ...binArgs] } : null;
  }
  return { command: commandPath, args: binArgs };
}

/**
 * Escape one argv element so it survives BOTH parsers a Windows shell:true
 * spawn puts it through before the target CLI ever sees it:
 *   1. cmd.exe's own line tokenizer, which still scans for & | < > ^ % ! " ( )
 *      even inside a per-argument quoted segment — quoting alone does not
 *      shield cmd.exe metacharacters, and this runs a SECOND time when the
 *      resolved binary is itself a .cmd shim (npm's `ruflo`/`claude-flow`/
 *      `npx` global installs on Windows), because launching a .cmd file is
 *      cmd.exe re-invoking itself on the command line.
 *   2. The eventual CommandLineToArgvW argv parse in the target process,
 *      which needs backslash-before-quote sequences doubled and the value
 *      quoted so it lands as ONE argument.
 * Without this, a hook-derived value (e.g. a Bash tool's `command`, or a
 * file path) containing a shell metacharacter can be reinterpreted as a
 * separate command / redirection instead of reaching the CLI as literal
 * data — this is the class of bug in CVE-2024-27980 (Node's own .bat/.cmd
 * argument-injection advisory). Algorithm: https://qntm.org/cmd, the same
 * reference the `cross-spawn` package's Windows escaping is built from.
 *
 * Byte-identical to plugins/ruflo-core/scripts/ruflo-hook.cjs so the four
 * copies can be diffed against each other. This is the fallback, not the
 * primary defence: resolveInvocation() above is preferred because it removes
 * cmd.exe from the chain entirely rather than out-guessing its tokenizer.
 */
function escapeCmdArg(arg) {
  let s = String(arg);
  s = s.replace(/(\\*)"/g, '$1$1\\"').replace(/(\\*)$/, '$1$1');
  s = `"${s}"`;
  return s.replace(/[()%!^"<>&|;,]/g, '^$&');
}

function invokeHook(bin, binArgs, hookArgs, stdinData, options = {}) {
  const env = options.env || process.env;
  const platform = options.platform || process.platform;
  const spawnOpts = {
    input: stdinData || '',
    encoding: 'utf8',
    stdio: ['pipe', 'ignore', 'ignore'],
    timeout: 30_000,
    env,
  };

  // Layer 1: no shell. CreateProcess/execve receives the argv array
  // verbatim, so nothing in it can be reinterpreted as syntax.
  const invocation = resolveInvocation(bin, binArgs, { env, platform });
  if (invocation) {
    const result = spawnSync(invocation.command, [...invocation.args, ...hookArgs], {
      ...spawnOpts,
      shell: false,
    });
    return result.status === 0;
  }

  // Layer 2: Windows shim we could not map to an entrypoint. cmd.exe is
  // unavoidable here (CreateProcess cannot launch a .cmd, and Node has
  // refused to since CVE-2024-27980), so every element is escaped. Losing
  // the hook entirely would be the wrong trade — telemetry is best-effort,
  // but silently doing nothing hides breakage.
  const useShell = platform === 'win32';
  const args = [...binArgs, ...hookArgs];
  const result = spawnSync(
    useShell ? escapeCmdArg(bin) : bin,
    useShell ? args.map(escapeCmdArg) : args,
    { ...spawnOpts, shell: useShell },
  );
  return result.status === 0;
}

function main() {
  const args = process.argv.slice(2);
  if (args.length === 0) done();

  const [subcommand, ...rest] = args;

  let stdinData = '';
  try { stdinData = fs.readFileSync(0, 'utf8'); } catch { stdinData = ''; }

  const hookArgs = ['hooks', subcommand, ...rest];

  // Presence is checked separately from invocation strategy: a command that
  // exists but cannot be resolved to an entrypoint still runs, via layer 2.
  if (resolveCommandPath('ruflo')) { invokeHook('ruflo', [], hookArgs, stdinData); done(); }
  if (resolveCommandPath('claude-flow')) { invokeHook('claude-flow', [], hookArgs, stdinData); done(); }
  // --prefix: npx resolves ruflo from this helper's install root, never from
  // the cwd (the opened project); the CLI still runs in the project.
  invokeHook('npx', ['--prefer-offline', '--yes', '--prefix', path.resolve(__dirname, '..', '..'), 'ruflo@latest'], hookArgs, stdinData);
  done();
}

// Test seam: the Windows argv suite require()s this file to drive resolveInvocation()
// and invokeHook() with a simulated { platform: 'win32', env } — which is how
// the Windows branch is proved from a Linux/macOS CI run. hooks.json always
// invokes this file directly, so main() runs unconditionally otherwise.
if (!globalThis.__RUFLO_HOOK_IMPORT_ONLY__) main();

module.exports = { invokeHook, resolveCommandPath, resolveInvocation, resolveNpmShim, escapeCmdArg };
