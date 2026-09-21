@php
    $display = static function (mixed $value) use (&$display): string {
        return is_array($value) ? implode("\n", array_map(static fn ($item): string => $display($item), $value)) : (is_scalar($value) ? (string) $value : '');
    };
    $date = static function (mixed $value): string {
        $text = is_scalar($value) ? (string) $value : '';
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $parts) ? $parts[3].'.'.$parts[2].'.'.$parts[1] : $text;
    };
    $roles = [
        'developer_control_representative' => 'Представитель застройщика, технического заказчика, лица, ответственного за эксплуатацию, или регионального оператора по строительному контролю',
        'construction_representative' => 'Представитель лица, осуществляющего строительство, реконструкцию, капитальный ремонт',
        'contractor_control_representative' => 'Представитель лица, осуществляющего строительство, по вопросам строительного контроля',
        'designer_representative' => 'Представитель лица, осуществляющего подготовку проектной документации (при привлечении)',
        'direct_work_executor' => 'Представитель лица, выполнившего работы (при выполнении по договору)',
        'axis_layout_executor' => 'Представитель лица, выполнившего разбивку осей (при выполнении по договору)',
        'geodetic_base_executor' => 'Представитель лица, выполнившего создание геодезической разбивочной основы (при выполнении по договору)',
        'structure_executor' => 'Представитель лица, выполнившего строительные конструкции (при выполнении по договору)',
        'network_executor' => 'Представитель лица, выполнившего участки сетей (при выполнении по договору)',
        'operating_company_representative' => 'Представитель организации, эксплуатирующей сети',
        'other' => 'Иной представитель',
    ];
    $formRoles = ['developer_control_representative', 'construction_representative', 'contractor_control_representative', 'designer_representative', match ($type) {
        'axis_layout_act' => 'axis_layout_executor',
        'geodetic_base_acceptance_act' => 'geodetic_base_executor',
        'responsible_structure_act' => 'structure_executor',
        'engineering_network_section_act' => 'network_executor',
        default => 'direct_work_executor',
    }];
    if ($type === 'engineering_network_section_act') $formRoles[] = 'operating_company_representative';
    $orderedSignatories = [];
    foreach ($formRoles as $role) {
        $matching = array_values(array_filter($signatories, static fn ($person) => ($person['role'] ?? null) === $role));
        array_push($orderedSignatories, ...($matching ?: [['role' => $role]]));
    }
    $signatories = array_merge($orderedSignatories, array_values(array_filter($signatories, static fn ($person) => !in_array($person['role'] ?? null, $formRoles, true))));
    foreach (['developer' => 'Застройщик / технический заказчик / иное уполномоченное лицо', 'construction' => 'Лицо, осуществляющее строительство', 'designer' => 'Лицо, осуществляющее подготовку проектной документации'] as $role => $label) {
        if (!in_array($role, array_column($participants, 'role'), true)) $participants[] = ['role' => $role, 'role_label' => $label];
    }
@endphp
<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>{{ $officialTitle }}</title>
<style>
@page { size: A4; margin: 18mm 17mm 21mm; }
body { color: #111; font-family: DejaVu Serif, serif; font-size: 10pt; line-height: 1.4; }
h1 { font-size: 13pt; text-align: center; margin: 0 0 6mm; }
h2 { font-size: 11pt; margin: 5mm 0 2mm; page-break-after: avoid; }
p { margin: 1.5mm 0; overflow-wrap: break-word; }
.label { font-weight: bold; page-break-after: avoid; }
.value { white-space: pre-wrap; overflow-wrap: break-word; margin-bottom: 3mm; }
.blank { border-bottom: .5pt solid #555; min-height: 5mm; margin-bottom: 3mm; }
.signature-line { margin: 4mm 0 3mm; }
.footer { position: fixed; bottom: -13mm; left: 0; right: 0; font-size: 8pt; text-align: right; }
.footer .page:after { content: counter(page); }
.section { margin-top: 3mm; }
</style></head><body>
<div class="footer">Лист <span class="page"></span></div>
<h1>{{ $officialTitle }}</h1>
<p><strong>№ {{ $profile['act_number'] ?? '________________' }}</strong> от {{ $date($document['document_date'] ?? '') ?: '________________' }}</p>
<p class="label">Объект капитального строительства</p>
<div class="value">{{ $project['name'] ?? '' }}@if(!empty($project['address']))<br>
{{ $project['address'] }}@endif</div>
@if(empty($project['name']))<div class="blank"></div>@endif
@if(!empty($profile['location']) || !empty($profile['structure_location']))
<p class="label">Место выполнения работ, участок</p><div class="value">{{ $profile['location'] ?? $profile['structure_location'] }}</div>
@endif
<h2>Участники строительства</h2>
@forelse($participants as $participant)
<p class="label">{{ $participant['role_label'] ?? $roles[$participant['role'] ?? ''] ?? 'Участник строительства' }}</p>
<div class="value">{{ $display($participant['name'] ?? $participant['organization'] ?? '') }}
@foreach(['inn' => 'ИНН', 'ogrn' => 'ОГРН / ОГРНИП', 'address' => 'Адрес', 'phone' => 'Телефон', 'sro_name' => 'СРО', 'sro_ogrn' => 'ОГРН СРО', 'sro_inn' => 'ИНН СРО'] as $key => $label)
@if(!empty($participant[$key])){{ $label }}: {{ $display($participant[$key]) }}
@endif
@endforeach</div>
@if(empty($participant['name']) && empty($participant['organization']))<div class="blank"></div>@endif
@empty
<p>Застройщик / технический заказчик / иное уполномоченное лицо</p><div class="blank"></div>
<p>Лицо, осуществляющее строительство, реконструкцию, капитальный ремонт</p><div class="blank"></div>
<p>Лицо, осуществляющее подготовку проектной документации</p><div class="blank"></div>
@endforelse
<h2>Представители, участвующие в освидетельствовании</h2>
@foreach($signatories as $signatory)
<p class="label">{{ $signatory['role_label'] ?? $roles[$signatory['role'] ?? ''] ?? 'Представитель' }}</p>
<div class="value">{{ $signatory['position'] ?? '' }} {{ $signatory['name'] ?? $signatory['signer_name'] ?? '' }}
{{ $signatory['organization'] ?? $signatory['organization_name'] ?? '' }}
@if(!empty($signatory['nrs_number']))Номер в НРС: {{ $signatory['nrs_number'] }}
@endif
@if(!empty($signatory['authority_document']))Основание полномочий: {{ $signatory['authority_document'] }}
@endif
@foreach(['inn' => 'ИНН', 'ogrn' => 'ОГРН / ОГРНИП', 'address' => 'Адрес', 'sro_name' => 'СРО', 'sro_ogrn' => 'ОГРН СРО', 'sro_inn' => 'ИНН СРО'] as $key => $label)
@if(!empty($signatory[$key])){{ $label }}: {{ $display($signatory[$key]) }}
@endif
@endforeach</div>
@if(empty($signatory['name']) && empty($signatory['signer_name']))<div class="blank"></div>@endif
@endforeach
<h2>Сведения по результатам освидетельствования</h2>
@foreach($sections as $section)
<div class="section"><p class="label">{{ $section['number'] !== '' ? $section['number'].'. ' : '' }}{{ $section['label'] }}</p>
@if($section['value'] !== null && $section['value'] !== '' && $section['value'] !== [])
@foreach(explode("\n", $display($section['value'])) as $line)<p class="value">{{ preg_match('/^\d{4}-\d{2}-\d{2}$/', $line) ? $date($line) : $line }}</p>@endforeach
@else<div class="blank"></div>@endif</div>
@endforeach
@if($relations !== [])
<h2>Приложенные и связанные документы</h2>
@foreach($relations as $relation)
@php($reference = $relation['document_snapshot'] ?? $relation['domain_snapshot'] ?? [])
<p>{{ $loop->iteration }}. {{ $reference['title'] ?? $reference['name'] ?? $relation['label'] ?? 'Документ' }}
{{ implode(' ', array_filter([
    !empty($reference['number']) ? '№ '.$reference['number'] : null,
    !empty($reference['document_date']) ? 'от '.$date($reference['document_date']) : null,
    !empty($reference['version_number']) ? '(версия '.$reference['version_number'].')' : null,
])) }}</p>
@endforeach
@endif
<h2>Подписи представителей</h2>
@foreach($signatories as $signatory)
<p class="label">{{ $signatory['role_label'] ?? $roles[$signatory['role'] ?? ''] ?? 'Представитель' }}</p>
<p class="signature-line">____________________ / {{ $signatory['name'] ?? $signatory['signer_name'] ?? '____________________' }} /</p>
@endforeach
<p>Количество экземпляров: {{ $document['copies_count'] ?? '____' }}</p>
</body></html>
