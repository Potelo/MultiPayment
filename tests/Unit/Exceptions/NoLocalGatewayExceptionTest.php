<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;

/**
 * `GatewayException` significa resposta de erro do gateway, então ela só pode nascer onde uma
 * resposta é traduzida: nos classificadores dos drivers ou dentro de um `catch`. Este teste
 * percorre `src/` e falha ao encontrar `new GatewayException` (ou um construtor estático dela)
 * fora desses lugares, o que denunciaria uma regra local lançada como erro do gateway.
 */
class NoLocalGatewayExceptionTest extends TestCase
{
    /**
     * Métodos que traduzem a resposta do gateway e podem criar a exceção diretamente.
     */
    private const CLASSIFIERS = [
        'IuguGateway::translateIuguException',
        'IuguGateway::classifyIuguFailure',
        'StripeGateway::translateStripeException',
    ];

    public function testGatewayExceptionIsOnlyCreatedByClassifiersOrInsideACatch(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $file) {
            foreach (self::gatewayExceptionCreations($file) as $creation) {
                $context = $creation['class'] . '::' . $creation['function'];
                if ($creation['insideCatch'] || in_array($context, self::CLASSIFIERS, true)) {
                    continue;
                }
                $offenders[] = "{$creation['file']}:{$creation['line']} ({$context})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "GatewayException criada fora de um classificador ou de um catch (use ModelAttributeValidationException, "
            . "UnsupportedOperationException::restricted() ou ConfigurationException):\n" . implode("\n", $offenders)
        );
    }

    /**
     * A varredura reconhece os pontos legítimos, senão o teste passaria por não olhar nada.
     */
    public function testTheScanFindsTheClassifiers(): void
    {
        $found = [];
        foreach ($this->sourceFiles() as $file) {
            foreach (self::gatewayExceptionCreations($file) as $creation) {
                $found[] = $creation['class'] . '::' . $creation['function'];
            }
        }

        foreach (self::CLASSIFIERS as $classifier) {
            $this->assertContains($classifier, $found, "a varredura não encontrou {$classifier}");
        }
    }

    /**
     * Controle negativo sobre uma fixture: criação direta, construtor estático, nome qualificado
     * e `throw` depois de um `catch` já fechado são reportados fora de `catch`; só a criação
     * dentro do `catch` (inclusive numa closure) é marcada como tal, e `::class` é ignorado.
     */
    public function testTheScanReportsCreationsOutsideACatchAndTracksTheCatchDepth(): void
    {
        $code = <<<'PHP'
        <?php
        class Sample
        {
            public function direta(): void
            {
                $x = "{$this->nome} {$this->outro}";
                throw new GatewayException('local');
            }

            public function estatica(): void
            {
                $classe = GatewayException::class;
                throw GatewayException::methodNotFound('a', 'b');
            }

            public function qualificada(): void
            {
                throw new \Potelo\MultiPayment\Exceptions\GatewayException('local');
            }

            public function depoisDoCatch(): void
            {
                try {
                    $this->x();
                } catch (\Exception $e) {
                    $this->log($e);
                }
                throw new GatewayException('fora do catch');
            }

            public function dentroDoCatch(): void
            {
                try {
                    $this->x();
                } catch (\Exception $e) {
                    $f = function () use ($e) {
                        throw new GatewayException('closure', null, $e);
                    };
                    throw new GatewayException('direto', null, $e);
                }
            }

            public function arm(): int
            {
                return match (true) {
                    default => throw new GatewayException('arm'),
                };
            }
        }
        PHP;
        $path = tempnam(sys_get_temp_dir(), 'scan') . '.php';
        file_put_contents($path, $code);

        try {
            $method = new \ReflectionMethod(self::class, 'gatewayExceptionCreations');
            $creations = $method->invoke(null, $path);
        } finally {
            unlink($path);
        }

        $this->assertSame([
            ['direta', false],
            ['estatica', false],
            ['qualificada', false],
            ['depoisDoCatch', false],
            ['dentroDoCatch', true],
            ['dentroDoCatch', true],
            ['arm', false],
        ], array_map(static fn (array $c) => [$c['function'], $c['insideCatch']], $creations));
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $root = realpath(__DIR__ . '/../../../src');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && basename($file->getPathname()) !== 'GatewayException.php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        $this->assertNotEmpty($files);

        return $files;
    }

    /**
     * Ocorrências de `new GatewayException(` e de `GatewayException::metodo(` no arquivo, cada
     * uma com o método que a contém e se está dentro de um bloco `catch`.
     *
     * @param  string  $path
     * @return array<int, array{file: string, line: int, class: string, function: string, insideCatch: bool}>
     */
    private static function gatewayExceptionCreations(string $path): array
    {
        $tokens = token_get_all(file_get_contents($path));
        $class = basename($path, '.php');
        $creations = [];

        $braces = [];           // pilha de chaves abertas: 'code' ou 'interp' (interpolação em string)
        $function = null;
        $functionDepth = null;
        $catchDepths = [];
        $pendingFunction = false;
        $pendingCatch = false;
        $codeDepth = 0;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                [$id, $text, $line] = $token;

                if ($id === T_FUNCTION) {
                    $pendingFunction = true;
                } elseif ($pendingFunction && $id === T_STRING) {
                    $function = $text;
                    $functionDepth = null;
                    $pendingFunction = false;
                } elseif ($id === T_CATCH) {
                    $pendingCatch = true;
                } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $braces[] = 'interp';
                } elseif ($id === T_NEW) {
                    $next = self::nextSignificant($tokens, $i);
                    if (self::isGatewayExceptionName($next)) {
                        $creations[] = self::creation($path, $line, $class, $function, $catchDepths);
                    }
                } elseif ($id === T_DOUBLE_COLON && self::isGatewayExceptionName(self::previousSignificant($tokens, $i))) {
                    $next = self::nextSignificant($tokens, $i);
                    if (is_array($next) && $next[0] === T_STRING) {
                        $creations[] = self::creation($path, $line, $class, $function, $catchDepths);
                    }
                }

                continue;
            }

            if ($token === '(' && $pendingFunction) {
                // closure: não tem nome e não troca o método corrente
                $pendingFunction = false;
            } elseif ($token === '{') {
                $braces[] = 'code';
                $codeDepth++;
                if ($pendingCatch) {
                    $catchDepths[] = $codeDepth;
                    $pendingCatch = false;
                }
                if (!is_null($function) && is_null($functionDepth)) {
                    $functionDepth = $codeDepth;
                }
            } elseif ($token === '}') {
                $kind = array_pop($braces);
                if ($kind !== 'code') {
                    continue;
                }
                if (!empty($catchDepths) && end($catchDepths) === $codeDepth) {
                    array_pop($catchDepths);
                }
                if ($functionDepth === $codeDepth) {
                    $function = null;
                    $functionDepth = null;
                }
                $codeDepth--;
            }
        }

        return $creations;
    }

    /**
     * @param  array<int, array|string>  $tokens
     * @return array|string|null
     */
    private static function nextSignificant(array $tokens, int $from): array|string|null
    {
        for ($j = $from + 1, $count = count($tokens); $j < $count; $j++) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[$j];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array|string>  $tokens
     * @return array|string|null
     */
    private static function previousSignificant(array $tokens, int $from): array|string|null
    {
        for ($j = $from - 1; $j >= 0; $j--) {
            if (!is_array($tokens[$j]) || !in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $tokens[$j];
            }
        }

        return null;
    }

    private static function isGatewayExceptionName(array|string|null $token): bool
    {
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $name = ltrim($token[1], '\\');
        $basename = substr(strrchr('\\' . $name, '\\'), 1);

        return $basename === 'GatewayException';
    }

    /**
     * @return array{file: string, line: int, class: string, function: string, insideCatch: bool}
     */
    private static function creation(string $path, int $line, string $class, ?string $function, array $catchDepths): array
    {
        return [
            'file' => substr($path, strpos($path, '/src/') + 1),
            'line' => $line,
            'class' => $class,
            'function' => $function ?? '(fora de método)',
            'insideCatch' => !empty($catchDepths),
        ];
    }
}
