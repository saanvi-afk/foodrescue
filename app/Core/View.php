<?php
declare(strict_types=1);
namespace FoodRescue\Core;
final class View
{
    public static function render(string $template, array $variables = []): void
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        try {
            require BASE_PATH . '/resources/views/' . $template . '.php';
            $content = ob_get_clean();
        } catch (\Throwable $error) { ob_end_clean(); throw $error; }
        require BASE_PATH . '/resources/views/layout.php';
    }
}
