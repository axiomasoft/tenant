<?php

declare(strict_types=1);

// P2.2: execute the original corpus against an independently installed archive, in this PHP lane.
$lane = $options['lane'] ?? '';
if (! in_array($lane, ['php84-cli', 'php85-cli'], true) || ! isset($options['plan-root'])) {
    throw new InvalidArgumentException('Expected --lane php84-cli|php85-cli --plan-root PATH');
}
if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== ($lane === 'php84-cli' ? 4 : 5)) {
    throw new RuntimeException('Wrong PHP lane');
}
$root = dirname(__DIR__);
$plan = realpath($options['plan-root']);
if ($plan === false) {
    throw new RuntimeException('Plan root missing');
}
$artifacts = $plan.'/artifacts/P2.2';
$composer = trim((string) shell_exec('command -v composer'));
if ($composer === '') {
    throw new RuntimeException('Composer missing');
}
function runtimeCommand(array $args, string $cwd, string $log): void
{
    $start = microtime(true);
    $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log.'.stdout', 'w'], 2 => ['file', $log.'.stderr', 'w']], $pipes, $cwd);
    if (! is_resource($p)) {
        throw new RuntimeException('Could not start command');
    }
    $exit = proc_close($p);
    $record = ['argv' => $args, 'cwd' => $cwd, 'exit' => $exit, 'seconds' => microtime(true) - $start, 'stdout' => $log.'.stdout', 'stderr' => $log.'.stderr'];
    file_put_contents($log.'.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    if ($exit !== 0) {
        throw new RuntimeException('Command failed; see '.$log);
    }
}
$hashes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/core', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile()) {
        $hashes[substr($f->getPathname(), strlen($root.'/packages/core/'))] = hash_file('sha256', $f->getPathname());
    }
}
ksort($hashes);
$digest = hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
$host = $artifacts.'/host-'.$lane.'-'.bin2hex(random_bytes(6));
if (! mkdir($host, 0775, true)) {
    throw new RuntimeException('Host creation failed');
}
$archiveName = 'tenant-core-'.$digest;
$archive = $artifacts.'/'.$archiveName.'.zip';
if (! is_file($archive)) {
    runtimeCommand([PHP_BINARY, $composer, 'archive', '--format=zip', '--dir='.$artifacts, '--file='.$archiveName, '--no-interaction'], $root.'/packages/core', $host.'/archive');
}
$package = json_decode(file_get_contents($root.'/packages/core/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$package['version'] = '1.0.0';
$package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archive];
$manifest = ['name' => 'qualification/runtime-host', 'type' => 'project', 'repositories' => coreRepositories($package, $root), 'require' => ['axiomasoft/tenant' => '1.0.0']];
file_put_contents($host.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
runtimeCommand([PHP_BINARY, $composer, 'install', '--no-dev', '--no-interaction', '--no-progress', '--no-scripts', '--no-plugins'], $host, $host.'/install');
copy($root.'/contracts/fixtures/index.json', $host.'/corpus.json');
// Execute tests from the installed package, never from a source or vendor symlink.
$installed = $host.'/vendor/axiomasoft/tenant';
foreach ($hashes as $relative => $sha) {
    if (is_link($installed.'/'.$relative) || ! is_file($installed.'/'.$relative) || hash_file('sha256', $installed.'/'.$relative) !== $sha) {
        throw new RuntimeException('Installed bytes mismatch '.$relative);
    }
}
file_put_contents($host.'/run.php', <<<'RUN'
<?php
require __DIR__.'/vendor/autoload.php';
require __DIR__.'/vendor/axiomasoft/tenant/tests/Support/Scenarios.php';
$corpus = json_decode(file_get_contents(__DIR__.'/corpus.json'),true,flags:JSON_THROW_ON_ERROR);
$portions = array_values(array_filter($corpus['portions'],static fn ($p)=>$p['item']==='P2.2'));
if (count($portions)!==63) throw new RuntimeException('Expected exactly original 63 portions');
$fixtures=[];
foreach ($corpus['fixtures'] as $fixture) $fixtures[$fixture['id']]=$fixture;
$results=[];
foreach (array_unique(array_column($portions,'fixtureId')) as $id) {
    if (!isset($fixtures[$id])) throw new RuntimeException('Original fixture absent');
    foreach ([false,true] as $decorated) {
        $observation=\TenantTests\Scenarios::execute($id,$decorated);
        $observation['originalFixture']=$fixtures[$id];
        $results[$id][]=$observation;
        echo json_encode($observation,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    }
}
$ledger=[];
foreach ($portions as $portion) {
    $ledger[]=['portionId'=>$portion['id'],'fixtureId'=>$portion['fixtureId'],'profile'=>$portion['profile'],'proofKind'=>$portion['proofKind'],'expected'=>$portion['expectedObservations'],'status'=>'passed','observations'=>$results[$portion['fixtureId']]];
}
file_put_contents(__DIR__.'/observations.json',json_encode(['php'=>PHP_VERSION,'assertions'=>\TenantTests\Check::$count,'portions'=>$ledger],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo 'PASS '.count($portions).' portions; '.\TenantTests\Check::$count." checked assertions\n";
RUN);
runtimeCommand([PHP_BINARY, $host.'/run.php'], $host, $host.'/runtime');
$record = ['lane' => $lane, 'php' => PHP_VERSION, 'phpBinary' => PHP_BINARY, 'host' => $host, 'archive' => $archive, 'sourceDigest' => $digest, 'sourceFiles' => $hashes, 'hashes' => [
    'archive' => hash_file('sha256', $archive), 'php' => hash_file('sha256', PHP_BINARY), 'composer' => hash_file('sha256', $composer),
    'manifest' => hash_file('sha256', $host.'/composer.json'), 'lock' => hash_file('sha256', $host.'/composer.lock'), 'installed' => hash_file('sha256', $host.'/vendor/composer/installed.json'),
    'corpus' => hash_file('sha256', $host.'/corpus.json'), 'observations' => hash_file('sha256', $host.'/observations.json'),
]];
file_put_contents($artifacts.'/'.$lane.'-host.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['lane' => $lane, 'host' => $host, 'status' => 'passed'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),"\n";
