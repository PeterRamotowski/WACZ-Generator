<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AssetExtension extends AbstractExtension
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_vite_assets', [$this, 'getViteAssets']),
        ];
    }

    public function getViteAssets(string $entry = 'app'): array
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $manifestPath = $projectDir . '/public/build/.vite/manifest.json';

        if (!file_exists($manifestPath)) {
            return ['css' => [], 'js' => null];
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $entryKey = "assets/{$entry}.js";

        if (!isset($manifest[$entryKey])) {
            return ['css' => [], 'js' => null];
        }

        $entry = $manifest[$entryKey];

        return [
            'css' => $entry['css'] ?? [],
            'js' => $entry['file'] ?? null
        ];
    }
}
