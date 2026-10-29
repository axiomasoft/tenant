<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Closure;
use Tenant\Postgres\Contracts\RecoveryAnchor;

/** Keep on independent durable storage, never inside the catalog backup/restore cohort. */
final readonly class FileRecoveryAnchor implements RecoveryAnchor
{
    public function __construct(private string $path) {}
    public static function create(string $path): self
    {
        $anchor=new self($path);
        $anchor->locked(function()use($anchor):void {
            if (file_exists($anchor->path)) throw new \Tenant\Exceptions\RecoveryUnsupported('Recovery anchor already exists.');
            $anchor->save(['generation'=>bin2hex(random_bytes(16)),'entries'=>[]]);
        });
        return $anchor;
    }
    public function generation(): string { return $this->locked(fn():string=>$this->read()['generation']); }
    public function entries(): array { return $this->locked(fn():array=>$this->read()['entries']); }
    public function remember(string $keyHash, array $original): void
    {
        $this->locked(function()use($keyHash,$original):void {
            $data=$this->read();
            if (!preg_match('/^[a-f0-9]{64}$/D',$keyHash) || ($original['generation']??null) !== $data['generation']) throw new \RuntimeException('recovery_generation_mismatch');
            if (isset($data['entries'][$keyHash]) && $data['entries'][$keyHash] !== $original) throw new \Tenant\Exceptions\RetentionHistoryMissing('retention_history_missing: original anchor entry differs');
            $data['entries'][$keyHash]=$original;$this->save($data);
        });
    }
    public function advance(string $expectedGeneration): string
    {
        return $this->locked(function()use($expectedGeneration):string {
            $data=$this->read();
            if ($data['generation'] !== $expectedGeneration) throw new \RuntimeException('recovery_generation_mismatch');
            $data['generation']=bin2hex(random_bytes(16));$this->save($data);return $data['generation'];
        });
    }
    private function locked(Closure $work): mixed
    {
        $dir=dirname($this->path);
        if (!is_dir($dir) || is_link($dir) || is_link($this->path) || (fileperms($dir)&0077)!==0) throw new \Tenant\Exceptions\RecoveryUnsupported('Recovery anchor needs private independent directory.');
        $lock=fopen($this->path.'.lock','c');if ($lock===false) throw new \Tenant\Exceptions\RecoveryUnsupported('Recovery anchor lock unavailable.');
        chmod($this->path.'.lock',0600);
        try { if (!flock($lock,LOCK_EX)) throw new \Tenant\Exceptions\RecoveryUnsupported('Recovery anchor lock unavailable.');return $work(); }
        finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    private function read(): array
    {
        if (!is_file($this->path)) throw new \Tenant\Exceptions\RecoveryUnsupported('recovery_unsupported: external anchor unavailable');
        $data=json_decode((string)file_get_contents($this->path),true,flags:JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['generation']??null) || !preg_match('/^[a-f0-9]{32}$/D',$data['generation']) || !is_array($data['entries']??null)) throw new \Tenant\Exceptions\RecoveryUnsupported('Invalid recovery anchor.');
        return $data;
    }
    private function save(array $data): void
    {
        $tmp=$this->path.'.'.bin2hex(random_bytes(8));$file=fopen($tmp,'x');if ($file===false) throw new \Tenant\Exceptions\RecoveryUnsupported('Cannot persist anchor.');
        chmod($tmp,0600);$bytes=json_encode($data,JSON_THROW_ON_ERROR);
        try { if (fwrite($file,$bytes)!==strlen($bytes) || !fflush($file) || !fsync($file)) throw new \Tenant\Exceptions\RecoveryUnsupported('Cannot persist anchor.'); }
        finally { fclose($file); }
        if (!rename($tmp,$this->path)) throw new \Tenant\Exceptions\RecoveryUnsupported('Cannot commit anchor.');
        $directory=fopen(dirname($this->path),'r');
        if ($directory===false) throw new \Tenant\Exceptions\RecoveryUnsupported('Cannot sync anchor directory.');
        try { if (!fsync($directory)) throw new \Tenant\Exceptions\RecoveryUnsupported('Cannot sync anchor directory.'); } finally { fclose($directory); }
    }
}
