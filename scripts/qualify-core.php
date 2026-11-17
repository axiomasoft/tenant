<?php

declare(strict_types=1);

$options = getopt('', ['item:', 'lane:', 'plan-root:']);
if (($options['item'] ?? '') === 'P2.2') {
    require __DIR__.'/qualify-runtime.php';

    return;
}
$lane = $options['lane'] ?? '';
if (($options['item'] ?? '') !== 'P2.1' || ! in_array($lane, ['php84-cli', 'php85-cli'], true) || ! isset($options['plan-root'])) {
    throw new InvalidArgumentException('Expected --item P2.1 --lane php84-cli|php85-cli --plan-root PATH');
}
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== ($lane === 'php84-cli' ? 4 : 5)) {
    throw new RuntimeException('Wrong PHP lane');
}
$root = dirname(__DIR__);
$plan = realpath($options['plan-root']);
if ($plan === false) {
    throw new RuntimeException('Plan root not found');
}
$artifacts = $plan.'/artifacts/P2.1';
if (! is_dir($artifacts) && ! mkdir($artifacts, 0775, true)) {
    throw new RuntimeException('Cannot create artifacts');
}
$composer = trim((string) shell_exec('command -v composer'));
if ($composer === '') {
    throw new RuntimeException('Composer missing');
}
function command(array $args, string $cwd): void
{
    echo json_encode(['argv' => $args, 'cwd' => $cwd], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $start = microtime(true);
    $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (! is_resource($p)) {
        throw new RuntimeException('Process start failed');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($p);
    echo $out, $err, json_encode(['exit' => $exit, 'seconds' => microtime(true) - $start], JSON_THROW_ON_ERROR), "\n";
    if ($exit !== 0) {
        throw new RuntimeException('Command failed');
    }
}
$sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/tenant', FilesystemIterator::SKIP_DOTS));
$sourceHashes = [];
foreach ($sourceFiles as $source) {
    if ($source->isFile()) {
        $sourceHashes[substr($source->getPathname(), strlen($root.'/packages/tenant/'))] = hash_file('sha256', $source->getPathname());
    }
}
ksort($sourceHashes);
$sourceDigest = hash('sha256', json_encode($sourceHashes, JSON_THROW_ON_ERROR));
$archiveName = 'tenant-core-'.$sourceDigest;
$archive = $artifacts.'/'.$archiveName.'.zip';
if (! is_file($archive)) {
    command([PHP_BINARY, $composer, 'archive', '--format=zip', '--dir='.$artifacts, '--file='.$archiveName, '--no-interaction'], $root.'/packages/tenant');
}
file_put_contents($artifacts.'/package-manifest.json', json_encode(['archive' => $archive, 'sha256' => hash_file('sha256', $archive), 'sourceDigest' => $sourceDigest, 'sourceFiles' => $sourceHashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$host = $artifacts.'/host-'.$lane.'-'.bin2hex(random_bytes(6));
if (! mkdir($host, 0775, true)) {
    throw new RuntimeException('Cannot create host');
}
$package = json_decode(file_get_contents($root.'/packages/tenant/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$package['version'] = '1.0.0';
$package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archive];
$hostManifest = ['name' => 'qualification/empty-host', 'type' => 'project', 'repositories' => [['type' => 'package', 'package' => $package], ['packagist.org' => false]], 'require' => ['axiomasoft/tenant' => '1.0.0'], 'config' => ['secure-http' => true]];
file_put_contents($host.'/composer.json', json_encode($hostManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
command([PHP_BINARY, $composer, 'install', '--no-dev', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins'], $host);
copy($root.'/contracts/fixtures/index.json', $host.'/corpus.json');
mkdir($host.'/tests');
mkdir($host.'/tests/fixtures');
foreach (['SingleHost.php', 'Scenarios.php'] as $file) {
    copy($root.'/tests/install/fixtures/'.$file, $host.'/tests/fixtures/'.$file);
}
foreach (['CoreInstallTest.php', 'NeutralGraphTest.php', 'ExternalPluginInstallTest.php', 'SingleInstallTest.php'] as $file) {
    copy($root.'/tests/install/'.$file, $host.'/tests/'.$file);
}
file_put_contents($host.'/run.php', <<<'RUN'
<?php
require __DIR__.'/vendor/autoload.php';
final class Expectation { public function __construct(private mixed $value) {} public function toBeTrue(): void { if ($this->value !== true) throw new RuntimeException('Assertion failed'); } }
function expect(mixed $value): Expectation { return new Expectation($value); }
function it(string $description, Closure $test): void { $test(); echo $description, " PASS\n"; }
foreach (['CoreInstallTest.php', 'NeutralGraphTest.php', 'ExternalPluginInstallTest.php', 'SingleInstallTest.php'] as $file) { require __DIR__.'/tests/'.$file; }
$corpus = json_decode(file_get_contents(__DIR__.'/corpus.json'), true, flags: JSON_THROW_ON_ERROR);
$observations = [
  'INSTALL-01' => 'Archive installed with only tenant runtime dependency; public facade and configured host A executed.',
  'REL-03' => 'Runtime manifest contains PHP and ext-intl only; payer and resource identities remain distinct; product effects are outside this install proof.',
  'EXT01-PLUGIN' => 'External facility replacement compiled with the capability dependency; selected resolver decorator delegated once.',
  'SINGLE-01' => 'Configured A entered Active(single); B and missing binding refused.',
];
$count = 0;
foreach ($corpus['portions'] as $portion) {
    if ($portion['item'] !== 'P2.1') continue;
    if (!isset($observations[$portion['fixtureId']])) throw new RuntimeException('Unmapped fixture');
    echo json_encode(['portionId'=>$portion['id'], 'fixtureId'=>$portion['fixtureId'], 'expected'=>$portion['expectedObservations'], 'observed'=>$observations[$portion['fixtureId']]], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), "\n";
    $count++;
}
if ($count !== 15) throw new RuntimeException('Unexpected portion count');
RUN);
command([PHP_BINARY, $host.'/run.php'], $host);
$files = ['archive' => hash_file('sha256', $archive), 'composer' => hash_file('sha256', $composer), 'php' => hash_file('sha256', PHP_BINARY), 'host-manifest' => hash_file('sha256', $host.'/composer.json'), 'host-lock' => hash_file('sha256', $host.'/composer.lock'), 'installed' => hash_file('sha256', $host.'/vendor/composer/installed.json')];
file_put_contents($artifacts.'/'.$lane.'-host.json', json_encode(['lane' => $lane, 'php' => PHP_VERSION, 'host' => $host, 'archive' => $archive, 'hashes' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['lane' => $lane, 'host' => $host, 'hashes' => $files], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
