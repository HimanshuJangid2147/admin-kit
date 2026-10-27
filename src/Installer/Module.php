<?php

namespace AdminKit\Installer;

final class Module
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        private readonly array $manifest,
    ) {}

    public static function load(string $path): self
    {
        $data = json_decode(file_get_contents($path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        return new self($data['name'], $path, $data);
    }

    public function description(): string
    {
        return $this->manifest['description'] ?? '';
    }

    /** @return string[] */
    public function requires(): array
    {
        return $this->manifest['requires'] ?? [];
    }

    /** @return array<string,string> stub path (under files/) => destination (under base_path) */
    public function files(): array
    {
        return $this->manifest['files'] ?? [];
    }

    /** @return array<string,string> stub path (under files/) => migration name */
    public function migrations(): array
    {
        return $this->manifest['migrations'] ?? [];
    }

    /** @return array<string,string> */
    public function env(): array
    {
        return $this->manifest['env'] ?? [];
    }

    /** @return string[] */
    public function providers(): array
    {
        return $this->manifest['providers'] ?? [];
    }

    /** @return string[] */
    public function composer(): array
    {
        return $this->manifest['composer'] ?? [];
    }

    /** @return string[] */
    public function npm(): array
    {
        return $this->manifest['npm'] ?? [];
    }

    /** @return string[] */
    public function notes(): array
    {
        return $this->manifest['notes'] ?? [];
    }
}
