<?php

declare(strict_types=1);
namespace Tenant\Laravel\Internal\Codec;

/** @internal Bounded structural scan before json_decode can discard duplicate keys. */
final class StrictJson
{
    private int $offset = 0;
    public function __construct(private readonly string $json) {}
    public function object(): \stdClass
    {
        if (strlen($this->json) > 16384 || !preg_match('//u', $this->json)) throw new \InvalidArgumentException('Invalid context JSON');
        // Validate syntax without building a map, then reject duplicate decoded keys.
        if (!json_validate($this->json, 32)) throw new \JsonException('Invalid context JSON syntax');
        if (!str_starts_with(ltrim($this->json), '{')) throw new \InvalidArgumentException('Context must be an object');
        $this->scan(0);
        $value = json_decode($this->json, false, 32, JSON_THROW_ON_ERROR);
        if (!$value instanceof \stdClass) throw new \InvalidArgumentException('Context must be an object');
        return $value;
    }
    private function whitespace(): void { while (isset($this->json[$this->offset]) && str_contains(" \t\r\n", $this->json[$this->offset])) $this->offset++; }
    private function string(): string
    {
        $start = $this->offset++;
        while (isset($this->json[$this->offset])) {
            $c = $this->json[$this->offset++];
            if ($c === '\\') { $this->offset++; continue; }
            if ($c === '"') {
                $value = json_decode(substr($this->json, $start, $this->offset - $start), true, 2, JSON_THROW_ON_ERROR);
                if (!is_string($value)) throw new \InvalidArgumentException('Invalid key');
                return $value;
            }
        }
        throw new \InvalidArgumentException('Invalid string');
    }
    private function scan(int $depth): void
    {
        $this->whitespace();
        $c = $this->json[$this->offset] ?? '';
        if ($c === '"') { $this->string(); return; }
        if ($c !== '{' && $c !== '[') {
            while (isset($this->json[$this->offset]) && !str_contains(",]} \t\r\n", $this->json[$this->offset])) $this->offset++;
            return;
        }
        if (++$depth > 4) throw new \InvalidArgumentException('Context depth exceeded');
        $object = $c === '{'; $close = $object ? '}' : ']'; $seen = [];
        $this->offset++; $this->whitespace();
        if (($this->json[$this->offset] ?? '') === $close) { $this->offset++; return; }
        while (true) {
            $this->whitespace();
            if ($object) {
                $key = $this->string();
                if (isset($seen[$key])) throw new \InvalidArgumentException('Duplicate JSON key');
                $seen[$key] = true; $this->whitespace(); $this->offset++;
            }
            $this->scan($depth); $this->whitespace();
            if (($this->json[$this->offset++] ?? '') === $close) return;
        }
    }
}
