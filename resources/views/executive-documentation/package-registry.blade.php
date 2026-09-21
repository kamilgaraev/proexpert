<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><title>Реестр комплекта</title>
<style>body{font-family:Arial,sans-serif;margin:24px;color:#111}table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:8px;text-align:left;overflow-wrap:anywhere}thead{display:table-header-group}@media print{body{margin:0}}</style></head>
<body><h1>Реестр передачи № {{ $registry['number'] }}</h1>
<p>Идентификатор передачи: {{ $registry['transmittal_id'] }}. Контрольный хэш состава: {{ $registry['manifest_hash'] }}</p>
<table><thead><tr><th>№</th><th>Документ</th><th>Версия</th><th>Файл</th><th>SHA-256</th></tr></thead><tbody>
@foreach ($registry['documents'] as $row)
<tr><td>{{ $loop->iteration }}</td><td>{{ $row['title'] }}</td><td>{{ $row['version_number'] }}</td><td>{{ $row['file'] }}</td><td>{{ $row['sha256'] }}</td></tr>
@endforeach
</tbody></table><p>Состав соответствует зафиксированной передаче. Наличие файла и контрольного хэша не подтверждает подпись и нормативную корректность содержания.</p></body></html>
