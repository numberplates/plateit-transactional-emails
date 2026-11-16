<?php
declare(strict_types=1);

namespace App;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Renders email templates with company overrides and default fallbacks.
 */
final class Templates
{
    /**
     * Sets the application root containing bundled templates and local overrides.
     */
    public function __construct(private string $root, private array $env = []) {}

    /**
     * Renders the subject, plain text body and HTML body for an event.
     */
    public function render(string $eventDirectory, int $companyId, array $payload): array
    {
        $base = $this->root . '/src/templates/' . $eventDirectory;
        $shared = $this->root . '/src/templates/shared';
        $overrides = $this->root . '/overrides';
        $paths = array_values(array_filter([
            $overrides . '/' . $eventDirectory . '/companies/' . $companyId,
            $base,
            $overrides . '/shared/companies/' . $companyId,
            $shared,
        ], 'is_dir'));
        // Preserve the original payload fields and expose the order consistently.
        $context = $payload;
        $assetBaseUrl = rtrim(trim($this->env['ASSETS_BASE_URL'] ?? ''), '/');
        if ($assetBaseUrl !== '' && (!filter_var($assetBaseUrl, FILTER_VALIDATE_URL) ||
            !in_array(strtolower(parse_url($assetBaseUrl, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true))) {
            throw new \RuntimeException('ASSETS_BASE_URL must be an absolute HTTP(S) URL');
        }
        $context['assets_base_url'] = $assetBaseUrl;
        $context['order'] = $eventDirectory === 'order_place' ? $payload : $payload['order'];
        // Twig searches each directory in order, including for nested partials.
        $twig = new Environment(new FilesystemLoader($paths), [
            'strict_variables' => true,
            'autoescape' => static fn (string $name): string|false => str_ends_with($name, '.html.twig') ? 'html' : false,
        ]);
        // Cascade common styles through event and company customisations.
        $css = $this->readStyles($shared . '/styles.css');
        foreach ([
            $base . '/styles.css',
            $overrides . '/shared/companies/' . $companyId . '/styles.css',
            $overrides . '/' . $eventDirectory . '/companies/' . $companyId . '/styles.css',
        ] as $stylesheet) {
            if (is_file($stylesheet)) {
                $css .= "\n" . $this->readStyles($stylesheet);
            }
        }
        $subject = trim($twig->render('subject.twig', $context));
        $context['subject'] = $subject;
        $html = (new CssToInlineStyles())->convert($twig->render('email.html.twig', $context), $css);
        return [
            'subject' => $subject,
            'body' => $twig->render('email.twig', $context),
            'html' => $html,
        ];
    }

    /**
     * Reads a stylesheet and reports configuration errors before delivery.
     */
    private function readStyles(string $path): string
    {
        $css = file_get_contents($path);
        if ($css === false) {
            throw new \RuntimeException('Unable to read email stylesheet');
        }
        return $css;
    }
}
