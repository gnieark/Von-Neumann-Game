<?php

declare(strict_types=1);

namespace VonNeumannGame\Config;

final class JsonConfigLoader
{
    public function __construct(private readonly string $projectRoot) {}

    /**
     * @return array<mixed>
     */
    public function load(string $name): array
    {
        $config = [];
        foreach ($this->paths($name) as $path) {
            if (!is_file($path)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                continue;
            }

            $config = Config::merge($config, $data);
        }

        return $config;
    }

    /**
     * @return array<string>
     */
    private function paths(string $name): array
    {
        $base = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $name;

        return [
            $base . '.json',
            $base . '-local.json',
            $base . '.local.json',
        ];
    }
}
