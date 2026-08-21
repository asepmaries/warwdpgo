#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Publisher PHP-only untuk repository GitHub warwdpgo.
 *
 * Autentikasi mengikuti remote Git yang sudah dikonfigurasi (SSH, Git
 * Credential Manager, atau credential helper). Tidak ada token di source.
 *
 * Jalankan:
 *   php publish.php
 *   php publish.php --validate
 *   php publish.php --status
 *   php publish.php --publish --message "Pesan commit"
 */

const PROJECT_ROOT = __DIR__;

const REQUIRED_PROJECT_FILES = [
    '.gitattributes',
    '.gitignore',
    'dm.php',
    'install.sh',
    'lead.txt',
    'publish.php',
    'reload.txt',
    'target_srv.txt',
    'user_server_dm.txt',
    'user_server_wdp.txt',
    'waktu.txt',
    'war.php',
];

const TEST_FILES = [
    'test/install-entrypoint.sh',
    'test/install-php-default.sh',
    'test/install-performance.sh',
    'test/install-clock-marker.sh',
];

function printLine(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function printError(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

function readInput(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $input = fgets(STDIN);
    if ($input === false) {
        throw new RuntimeException('Input terminal ditutup.');
    }

    return trim($input);
}

function commandLabel(array $command): string
{
    return implode(' ', array_map(static function (string $part): string {
        $part = (string) preg_replace(
            '#(https?://)[^/@\s]+:[^/@\s]+@#i',
            '$1***:***@',
            $part
        );
        $part = (string) preg_replace(
            '/gh[pousr]_[A-Za-z0-9_]+/',
            '<redacted>',
            $part
        );

        if (preg_match('/^[A-Za-z0-9_\.\/:=@\\-]+$/', $part) === 1) {
            return $part;
        }

        return '"' . str_replace('"', '\\"', $part) . '"';
    }, $command));
}

function nullDevicePath(): string
{
    return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
}

function openProcess(array $command, array $descriptors)
{
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        PROJECT_ROOT,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException(
            'Tidak dapat menjalankan: ' . commandLabel($command)
        );
    }

    return [$process, $pipes];
}

function runCommand(array $command): void
{
    printLine('> ' . commandLabel($command));
    [$process] = openProcess(
        $command,
        [0 => ['file', nullDevicePath(), 'r'], 1 => STDOUT, 2 => STDERR]
    );
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(sprintf(
            'Command gagal (exit %d): %s',
            $exitCode,
            commandLabel($command)
        ));
    }
}

function captureCommand(array $command): string
{
    [$process, $pipes] = openProcess(
        $command,
        [
            0 => ['file', nullDevicePath(), 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ]
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $detail = trim((string) $stderr);
        throw new RuntimeException(sprintf(
            'Command gagal (exit %d): %s%s',
            $exitCode,
            commandLabel($command),
            $detail === '' ? '' : PHP_EOL . $detail
        ));
    }

    return trim((string) $stdout);
}

function requireProjectFile(string $relativePath): string
{
    $path = PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace(
        '/',
        DIRECTORY_SEPARATOR,
        $relativePath
    );
    if (!is_file($path)) {
        throw new RuntimeException('File wajib tidak ditemukan: ' . $relativePath);
    }

    return $path;
}

function validateProject(): void
{
    printLine('Validasi project PHP-only...');
    foreach (REQUIRED_PROJECT_FILES as $relativePath) {
        requireProjectFile($relativePath);
    }
    foreach (TEST_FILES as $relativePath) {
        requireProjectFile($relativePath);
    }

    runCommand([PHP_BINARY, '-l', 'war.php']);
    runCommand([PHP_BINARY, '-l', 'dm.php']);
    runCommand([PHP_BINARY, '-l', 'publish.php']);
    runCommand(['bash', '-n', 'install.sh']);
    foreach (TEST_FILES as $testFile) {
        runCommand(['bash', $testFile]);
    }
    runCommand(['git', 'diff', '--check']);

    printLine('Validasi berhasil.');
}

function loadGitContext(): array
{
    if (captureCommand(['git', 'rev-parse', '--is-inside-work-tree']) !== 'true') {
        throw new RuntimeException('Folder ini bukan repository Git.');
    }

    $branch = captureCommand([
        'git',
        'symbolic-ref',
        '--quiet',
        '--short',
        'HEAD',
    ]);
    if ($branch === '') {
        throw new RuntimeException('Tidak dapat publish dari detached HEAD.');
    }

    $remote = trim((string) getenv('PUBLISH_GIT_REMOTE'));
    if ($remote === '') {
        $remote = 'origin';
    }
    if (preg_match('/^[A-Za-z0-9._-]+$/', $remote) !== 1) {
        throw new RuntimeException('Nama remote tidak valid: ' . $remote);
    }

    $remoteUrl = captureCommand(['git', 'remote', 'get-url', $remote]);
    if (preg_match(
        '#^(https://github\.com/|git@github\.com:|ssh://git@github\.com/)#i',
        $remoteUrl
    ) !== 1) {
        throw new RuntimeException(
            'Remote publish wajib menunjuk repository GitHub: ' . $remote
        );
    }

    return [
        'branch' => $branch,
        'remote' => $remote,
        'remoteUrl' => $remoteUrl,
    ];
}

function askCommitMessage(): string
{
    $default = 'Publish PHP ' . date('Y-m-d H:i:s');
    $message = readInput('Pesan commit (Enter = "' . $default . '"): ');

    return $message === '' ? $default : $message;
}

function commitAndPush(array $git, string $commitMessage): void
{
    $commitMessage = trim($commitMessage);
    if ($commitMessage === '') {
        throw new InvalidArgumentException('Pesan commit tidak boleh kosong.');
    }

    printLine('Menyimpan perubahan ke Git...');
    runCommand(['git', 'add', '--all']);

    $status = captureCommand(['git', 'status', '--porcelain']);
    if ($status === '') {
        printLine('Tidak ada perubahan baru untuk di-commit.');
    } else {
        runCommand(['git', 'commit', '-m', $commitMessage]);
    }

    printLine(sprintf(
        'Push ke GitHub (%s/%s)...',
        $git['remote'],
        $git['branch']
    ));
    runCommand([
        'git',
        'push',
        $git['remote'],
        'HEAD:refs/heads/' . $git['branch'],
    ]);
}

function publishGithub(string $commitMessage): void
{
    validateProject();
    $git = loadGitContext();
    commitAndPush($git, $commitMessage);
    printLine();
    printLine('Publish GitHub berhasil.');
    printLine(
        'Installer akan memakai arsip source GitHub dari branch/tag/commit.'
    );
}

function showGitStatus(): void
{
    $git = loadGitContext();
    $status = captureCommand(['git', 'status', '--short', '--branch']);

    printLine();
    printLine('Remote : ' . $git['remote']);
    printLine('URL    : ' . $git['remoteUrl']);
    printLine('Branch : ' . $git['branch']);
    printLine('Status :');
    printLine($status === '' ? '  Bersih, tidak ada perubahan.' : $status);
}

function showMenu(): void
{
    printLine();
    printLine('========================================');
    printLine('       PUBLISH WARWDPGO - GITHUB');
    printLine('========================================');
    printLine('1. Validasi, commit, dan push');
    printLine('2. Validasi saja');
    printLine('3. Lihat status Git');
    printLine('0. Keluar');
    printLine('========================================');
}

function showHelp(): void
{
    printLine('Publisher PHP-only untuk GitHub.');
    printLine();
    printLine('Penggunaan:');
    printLine('  php publish.php');
    printLine('  php publish.php --validate');
    printLine('  php publish.php --status');
    printLine('  php publish.php --publish --message "Pesan commit"');
    printLine();
    printLine('Autentikasi memakai remote Git/credential helper yang sudah aktif.');
    printLine('Env opsional: PUBLISH_GIT_REMOTE (default: origin).');
}

function optionValue(array $argv, string $name): ?string
{
    $index = array_search($name, $argv, true);
    if ($index === false) {
        return null;
    }
    $valueIndex = $index + 1;
    if (!isset($argv[$valueIndex])
        || strpos($argv[$valueIndex], '--') === 0
    ) {
        throw new InvalidArgumentException($name . ' membutuhkan nilai.');
    }

    return $argv[$valueIndex];
}

function main(array $argv): void
{
    $arguments = array_slice($argv, 1);
    if (in_array('--help', $arguments, true)
        || in_array('-h', $arguments, true)
    ) {
        showHelp();
        return;
    }
    if (in_array('--validate', $arguments, true)) {
        validateProject();
        return;
    }
    if (in_array('--status', $arguments, true)) {
        showGitStatus();
        return;
    }
    if (in_array('--publish', $arguments, true)) {
        $message = optionValue($arguments, '--message')
            ?? ('Publish PHP ' . date('Y-m-d H:i:s'));
        publishGithub($message);
        return;
    }
    if ($arguments !== []) {
        throw new InvalidArgumentException(
            'Argumen tidak dikenal. Jalankan --help.'
        );
    }

    while (true) {
        showMenu();
        $choice = readInput('Pilih menu [0-3]: ');

        switch ($choice) {
            case '1':
                publishGithub(askCommitMessage());
                return;

            case '2':
                validateProject();
                return;

            case '3':
                showGitStatus();
                readInput(PHP_EOL . 'Tekan Enter untuk kembali ke menu...');
                break;

            case '0':
                printLine('Publish dibatalkan.');
                return;

            default:
                printLine('Pilihan tidak valid. Masukkan angka 0 sampai 3.');
        }
    }
}

try {
    main($argv);
} catch (Throwable $error) {
    printError('');
    printError('PUBLISH GAGAL: ' . $error->getMessage());
    exit(1);
}
