@echo off

echo Проверка спецификации OpenAPI...
npx @redocly/cli lint openapi.yaml

echo Сборка HTML документации...
npx @redocly/cli build-docs openapi.yaml -o index.html

echo Документация успешно собрана в index.html 