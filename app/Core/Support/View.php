<?php

declare(strict_types=1);

namespace App\Core\Support;

final class View
{
    public function __construct(private readonly string $viewsPath)
    {
    }

    public function render(string $template, array $data = []): string
    {
        $path = $this->viewsPath . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        $renderPartial = fn (string $t, array $d = []) => $this->render($t, $d);

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;

        return (string) ob_get_clean();
    }
}
