<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Tenant\Postgres\Contracts\TemplateIntegrityVerifier;
use Tenant\Postgres\Data\ClaimRequest;
use Tenant\Postgres\Data\QualifiedTemplate;
use Tenant\Postgres\Internal\ClaimResource;

require_once __DIR__.'/public-errors-autoload.php';
require getenv('P41_AUTOLOAD');
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tenant\\Postgres\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__.'/../../packages/tenant-postgres/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    }
});
$run = getenv('P41_RUN_ID');
if (! preg_match('/^[a-f0-9]{12}$/D', $run) || getenv('P41_DB') !== 'tenant_p41_'.$run.'_central_test' || getenv('P41_HOST') !== '127.0.0.1' || getenv('P41_USER') !== 'tenant_p41_'.$run.'_runtime') {
    throw new RuntimeException('Unsafe P4.1 test connection');
}
$capsule = new Manager;
$capsule->addConnection(['driver'=>'pgsql','host'=>getenv('P41_HOST'),'port'=>getenv('P41_PORT'),'database'=>getenv('P41_DB'),'username'=>getenv('P41_USER'),'password'=>getenv('P41_PASSWORD'),'charset'=>'utf8','prefix'=>'','schema'=>'public'], 'lab');
$c = $capsule->getConnection('lab');
if ($c->selectOne('select current_database() db, current_user role')->db !== getenv('P41_DB')) throw new RuntimeException('Wrong database');
$input=json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$c->select('SELECT set_config(?, ?, false)', ['application_name', $input['name'] ?? 'p41-claim']);
$verifier = new class($input['pause'] ?? false) implements TemplateIntegrityVerifier {
    public function __construct(private readonly bool $pause) {}
    public function qualify(ClaimRequest $r): QualifiedTemplate {
        if ($this->pause) { echo "ready\n"; flush(); if (trim((string) fgets(STDIN)) !== 'release') throw new RuntimeException('Barrier aborted'); }
        return new QualifiedTemplate($r->clusterId,$r->templateId,1,str_repeat('a',64));
    }
};
try {
    $result=(new ClaimResource('lab',$verifier,$capsule->getDatabaseManager()))->claim(new ClaimRequest($input['key'],$input['cluster'],$input['template'],$input['digest']));
    echo json_encode(['resource'=>$result->resourceId,'replayed'=>$result->replayed], JSON_THROW_ON_ERROR),"\n";
} catch (Throwable $e) {
    echo json_encode(['error'=>get_class($e),'message'=>$e->getMessage()], JSON_THROW_ON_ERROR),"\n";
    exit(2);
}
