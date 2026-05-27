# Проверки текстов суперадминки

Эти команды фиксируют автоматический минимум для UI-copy Filament-суперадминки. Проверка намеренно ограничена `app/Filament` и переводами, которые реально используются суперадминкой: общий `lang/ru` содержит доменные ключи других модулей, например календарные ограничения работ, и не является корректной границей для этого UI-аудита.

```powershell
rg -n "fallback|legacy|payload|dto|exception|sql|constraint" app\Filament lang\ru\activity.php lang\ru\widgets.php lang\ru\blog_cms.php lang\ru\support_workspace.php lang\ru\filament_navigation.php
```

Ожидаемый результат: нет совпадений.

```powershell
rg -n -- "->label\('Preview'|->label\('Autosave'|->label\('Callout'|->label\('Embed'|->label\('CTA'|Section::make\('System'|Section::make\('Content'" app\Filament
```

Ожидаемый результат: нет совпадений. Короткие профессиональные обозначения `Email`, `ID`, `Slug`, `SEO`, `Open Graph`, `RSS` и `MIME` допустимы, если они являются привычными названиями полей.
