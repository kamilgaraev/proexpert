<?php

namespace App\Console\Commands\Blog;

use Illuminate\Console\Command;
use App\Services\Blog\BlogArticleService;

class ProcessScheduledArticlesCommand extends Command
{
    protected $signature = 'blog:process-scheduled';

    protected $description = 'Обрабатывает запланированные к публикации статьи блога';

    public function __construct(
        private BlogArticleService $articleService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Обработка запланированных статей...');

        try {
            $publishedCount = $this->articleService->processScheduledArticles();
            
            if ($publishedCount > 0) {
                $this->info("Опубликовано статей: {$publishedCount}");
            } else {
                $this->info('Нет статей для публикации');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Ошибка при обработке запланированных статей: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
} 