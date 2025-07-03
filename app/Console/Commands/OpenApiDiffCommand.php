<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OpenApi\OpenApiDiffService;

class OpenApiDiffCommand extends Command
{
    protected $signature = 'openapi:diff {--deep : Глубокая проверка тел запросов/ответов} {--only= : Ограничить deep-проверку (requests|responses)}';
    protected $description = 'Сравнить маршруты Laravel с документацией OpenAPI';

    public function handle(): int
    {
        $service = new OpenApiDiffService();

        if ($this->option('deep')) {
            $only = $this->option('only');
            $requests = $only === 'responses' ? false : true;
            $responses = $only === 'requests' ? false : true;
            $deep = $service->deepDiff($requests, $responses);

            $hasIssues = false;

            if ($requests && !empty($deep['requests'])) {
                $this->error('Несоответствия схем запросов:');
                foreach ($deep['requests'] as $route => $issues) {
                    $this->line($route);
                    foreach ($issues as $type => $fields) {
                        foreach ($fields as $field => $detail) {
                            if ($type === 'mismatched') {
                                $this->line("  [$type] $field => expected {$detail['expected']}, actual {$detail['actual']}");
                            } else {
                                $this->line("  [$type] $field");
                            }
                        }
                    }
                }
                $hasIssues = true;
            }

            if ($responses && !empty($deep['responses'])) {
                $this->error('Несоответствия схем ответов (Resources):');
                foreach ($deep['responses'] as $name => $issues) {
                    if (isset($issues['missing_in_spec'])) {
                        $this->line("$name отсутствует в OpenAPI");
                        $hasIssues = true;
                        continue;
                    }
                    $this->line($name);
                    foreach ($issues as $type => $fields) {
                        foreach ($fields as $field => $detail) {
                            if (is_array($detail) && isset($detail['expected'])) {
                                $this->line("  [$type] $field => expected {$detail['expected']}, actual {$detail['actual']}");
                            } else {
                                $this->line("  [$type] $field");
                            }
                        }
                    }
                    $hasIssues = true;
                }
            }

            if ($responses && !empty($deep['components'])) {
                $this->error('Несоответствия DTO/Component схем:');
                foreach ($deep['components'] as $name => $issues) {
                    if (isset($issues['missing_in_spec'])) {
                        $this->line("$name отсутствует в OpenAPI");
                        $hasIssues = true;
                        continue;
                    }
                    $this->line($name);
                    foreach ($issues as $type => $fields) {
                        foreach ($fields as $field => $detail) {
                            if (is_array($detail) && isset($detail['expected'])) {
                                $this->line("  [$type] $field => expected {$detail['expected']}, actual {$detail['actual']}");
                            } else {
                                $this->line("  [$type] $field");
                            }
                        }
                    }
                    $hasIssues = true;
                }
            }

            if ($hasIssues) {
                return self::FAILURE;
            }
            $this->info('Глубокая проверка прошла без расхождений.');
            return self::SUCCESS;
        } else {
            $diff = $service->diff();
            $undocumented = $diff['undocumented'];
            $obsolete = $diff['obsolete'];
            if ($undocumented) {
                $this->error('Маршруты без документации:');
                foreach ($undocumented as $route) {
                    $this->line($route);
                }
            }
            if ($obsolete) {
                $this->error('Документация без маршрута:');
                foreach ($obsolete as $route) {
                    $this->line($route);
                }
            }
            if (!$undocumented && !$obsolete) {
                $this->info('Все маршруты покрыты документацией.');
                return self::SUCCESS;
            }
            return self::FAILURE;
        }
    }
} 