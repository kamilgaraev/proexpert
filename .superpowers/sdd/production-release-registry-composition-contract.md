# РљРѕРЅС‚СЂР°РєС‚ production composition РґР»СЏ РїСѓР±Р»РёРєР°С†РёРё РѕС‚С‡С‘С‚РѕРІ

РЎС‚Р°С‚СѓСЃ: design gate, production registry РЅР°РјРµСЂРµРЅРЅРѕ РЅРµ СЃС‡РёС‚Р°РµС‚СЃСЏ СЂРµР°Р»РёР·РѕРІР°РЅРЅС‹Рј.

## РџСЂРёС‡РёРЅР° РѕС‚РґРµР»СЊРЅРѕРіРѕ РєРѕРЅС‚СЂР°РєС‚Р°

`ProjectReportPublicationReleaseRequestRegistry` СЃРµР№С‡Р°СЃ РЅРµ РёРјРµРµС‚ СЂРµР°Р»РёР·Р°С†РёРё. JSON-resolver РїСЂРѕРІРµСЂСЏРµС‚ РєР°РЅРѕРЅРёС‡РЅРѕСЃС‚СЊ Рё СЃРІСЏР·Рё С‡РµС‚С‹СЂС‘С… РґРѕРєСѓРјРµРЅС‚РѕРІ, РЅРѕ РІРѕР·РІСЂР°С‰Р°РµС‚ С‚РѕР»СЊРєРѕ РјР°СЃСЃРёРІС‹. `ReportPublicationReleaseAdmission` С‚СЂРµР±СѓРµС‚ РґРѕРјРµРЅРЅС‹Рµ РѕР±СЉРµРєС‚С‹ Рё СЂРµР°Р»СЊРЅС‹Рµ production binding-РєРѕРјРїРѕРЅРµРЅС‚С‹. РЎРёРЅС‚РµС‚РёС‡РµСЃРєР°СЏ СЃР±РѕСЂРєР° СЌС‚РёС… РѕР±СЉРµРєС‚РѕРІ РёР»Рё РїРѕРґСЃС‚Р°РЅРѕРІРєР° С‚РµСЃС‚РѕРІС‹С… providers РЅР°СЂСѓС€Р°РµС‚ `assertProductionSafe` Рё РґРµР»Р°РµС‚ admission РЅРµРґРѕСЃС‚РѕРІРµСЂРЅС‹Рј. РџРѕСЌС‚РѕРјСѓ СЃР»РµРґСѓСЋС‰РёР№ Р±Р»РѕРє РѕР±СЏР·Р°РЅ СЃРЅР°С‡Р°Р»Р° СЂРµР°Р»РёР·РѕРІР°С‚СЊ composition РЅРёР¶Рµ; РїСѓСЃС‚РѕР№ registry РЅРµ СЂР°СЃС€РёСЂСЏРµС‚СЃСЏ РѕР±С…РѕРґРѕРј.

## РўРѕС‡РЅС‹Рµ РіСЂР°РЅРёС†С‹ Рё РёРЅС‚РµСЂС„РµР№СЃС‹

Р”РѕР±Р°РІРёС‚СЊ application-РїРѕСЂС‚ `ReportPublicationReleaseComposition` СЃ РјРµС‚РѕРґРѕРј `compose(ReportPublicationReleaseRequest $request, string $trustedReleaseDirectory): ReportPublicationResolvedReleaseRequest`.

`ProjectReportPublicationReleaseRequestRegistry` РїСЂРёРЅРёРјР°РµС‚ СЌС‚РѕС‚ РїРѕСЂС‚ С‡РµСЂРµР· РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Р№ `__construct` Рё С‚РѕР»СЊРєРѕ РґРµР»РµРіРёСЂСѓРµС‚ РµРјСѓ РїРѕСЃР»Рµ РїСЂРѕРІРµСЂРєРё request code/schema. РћС‚СЃСѓС‚СЃС‚РІСѓСЋС‰РёР№ composition binding РґРѕР»Р¶РµРЅ Р·Р°РІРµСЂС€Р°С‚СЊСЃСЏ `report_publication_release_composition_unavailable`, Р° РЅРµ fallback-РѕР±СЉРµРєС‚РѕРј.

Production adapter `FilesystemReportPublicationReleaseComposition` РїРѕР»СѓС‡Р°РµС‚ С‚РѕР»СЊРєРѕ СЂРµР°Р»СЊРЅС‹Рµ Р·Р°РІРёСЃРёРјРѕСЃС‚Рё:

- РєР°РЅРѕРЅРёС‡РµСЃРєРёР№ resolver РґР»СЏ request, candidate manifest, conformance evidence Рё proof template;
- `ReportDefinitionFactory` + `CandidateReportDefinitionRegistry` РґР»СЏ РїРѕСЃС‚СЂРѕРµРЅРёСЏ candidate definition;
- `ReportDefinitionBindingAssembler`/`ReportDefinitionBindingMap` РґР»СЏ binding СЃ С‚РµРј Р¶Рµ code Рё definition hash;
- `FilesystemReportConformanceEvidenceRepository`-СЃРѕРІРјРµСЃС‚РёРјС‹Р№ hydrate-РїР°СЂСЃРµСЂ evidence;
- `ReportPublicationProof::fromArray`;
- production `ReportPublicationReleaseEligibilityGate`.

Adapter РЅРµ РїСЂРёРЅРёРјР°РµС‚ `callable`, `mixed`, С‚РµСЃС‚РѕРІС‹Р№ factory РёР»Рё РѕР±СЉРµРєС‚С‹ РёР· `Tests\\`; РІСЃРµ concrete Р·Р°РІРёСЃРёРјРѕСЃС‚Рё С‚РёРїРёР·РёСЂРѕРІР°РЅС‹ Рё РѕР±СЏР·Р°С‚РµР»СЊРЅС‹.

## Trusted root Рё С„Р°Р№Р»РѕРІС‹Рµ РёРЅРІР°СЂРёР°РЅС‚С‹

Trusted root Р·Р°РґР°С‘С‚СЃСЏ РєРѕРЅС„РёРіСѓСЂР°С†РёРµР№ `reports.publication_release.trusted_directory` Рё РїРµСЂРµРґР°С‘С‚СЃСЏ РІ composition СЏРІРЅРѕ. РћРЅ РѕР±СЏР·Р°РЅ:

1. СЃСѓС‰РµСЃС‚РІРѕРІР°С‚СЊ, Р±С‹С‚СЊ directory Рё СЂР°Р·СЂРµС€Р°С‚СЊСЃСЏ С‡РµСЂРµР· `realpath`;
2. РЅРµ Р±С‹С‚СЊ symlink;
3. СЃРѕРґРµСЂР¶Р°С‚СЊ С‚РѕР»СЊРєРѕ request-С„Р°Р№Р» СЃ РёРјРµРЅРµРј `{request_id}.json` Рё СЂРѕРІРЅРѕ С‚СЂРё Р°СЂС‚РµС„Р°РєС‚Р° РёР· `artifact_paths`;
4. РЅРµ РїРѕР·РІРѕР»СЏС‚СЊ `..`, symlink РёР»Рё path traversal РїРѕСЃР»Рµ `realpath`;
5. С‡РёС‚Р°С‚СЊ canonical JSON (`CanonicalJson::encode(decoded) === bytes`);
6. СЃРІРµСЂСЏС‚СЊ request `commit_sha` СЃ candidate, conformance, proof, provenance Рё РѕР¶РёРґР°РµРјС‹Рј trusted checkout SHA;
7. СЃРІРµСЂСЏС‚СЊ РІСЃРµ SHA-256 СЃРІСЏР·РµР№ Рё СЃРїРёСЃРѕРє РѕР±СЏР·Р°С‚РµР»СЊРЅС‹С… checks СЃ `ReportPublicationAdmissionRequirements`.

Р›СЋР±РѕР№ missing, extra, malformed, wrong-commit РёР»Рё tampered РґРѕРєСѓРјРµРЅС‚ РґР°С‘С‚ РѕРґРёРЅ fail-closed РґРѕРјРµРЅРЅС‹Р№ `InvalidArgumentException`; С‡Р°СЃС‚РёС‡РЅС‹Р№ admission РЅРµ РІРѕР·РІСЂР°С‰Р°РµС‚СЃСЏ.

## Mapping invariants

- candidate definition РїСЂРµРѕР±СЂР°Р·СѓРµС‚СЃСЏ С‡РµСЂРµР· `ReportDefinitionFactory`; hash СЂР°РІРµРЅ `candidate_definition_sha256` РјР°РЅРёС„РµСЃС‚Р° Рё proof.
- СЂРµР·СѓР»СЊС‚Р°С‚ РѕР±РѕСЂР°С‡РёРІР°РµС‚СЃСЏ РІ `CandidateReportDefinition` С‚РѕР»СЊРєРѕ РїСЂРё `publication_readiness=candidate`.
- binding СЂР°Р·СЂРµС€Р°РµС‚СЃСЏ production `ReportDefinitionBindingMap` РїРѕ `(code, definitionHash)`; РѕС‚СЃСѓС‚СЃС‚РІРёРµ РёР»Рё mismatch вЂ” РѕС‚РєР°Р·.
- evidence hydrate-РёС‚СЃСЏ СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёРј canonical parser, РµРіРѕ code/hash/commit/status/assertion count РѕР±СЏР·Р°РЅС‹ СЃРѕРІРїР°РґР°С‚СЊ СЃ manifest Рё proof; `status` С‚РѕР»СЊРєРѕ `passed`.
- proof СЃС‚СЂРѕРёС‚СЃСЏ С‚РѕР»СЊРєРѕ `ReportPublicationProof::fromArray`; digest РѕР±СЏР·Р°РЅ СЃРѕРІРїР°РґР°С‚СЊ СЃ request `proof_sha256`.
- `verifiedChecks` СЂР°РІРµРЅ authoritative per-code СЃРїРёСЃРєСѓ Рё СЃРѕС…СЂР°РЅСЏРµС‚ РїРѕСЂСЏРґРѕРє; РЅРµР»СЊР·СЏ РїСЂРёРЅРёРјР°С‚СЊ РїРѕРґРјРЅРѕР¶РµСЃС‚РІРѕ.
- `assertProductionSafe()` РІС‹Р·С‹РІР°РµС‚СЃСЏ РґРѕ РІРѕР·РІСЂР°С‚Р° resolved request.
- gate СЃС‚СЂРѕРёС‚СЃСЏ РёР· production `ReportPublicationEligibilityService`, РЅРµ РёР· CI fixture.

## Replay semantics

РћРґРёРЅР°РєРѕРІС‹Р№ `request_id` + `commit_sha` + proof digest СЃС‡РёС‚Р°РµС‚СЃСЏ С‚РµРј Р¶Рµ immutable release Рё РјРѕР¶РµС‚ Р±С‹С‚СЊ РїРѕРІС‚РѕСЂРЅРѕ РїСЂРѕРІРµСЂРµРЅ Р±РµР· РёР·РјРµРЅРµРЅРёСЏ admission. Р›СЋР±Р°СЏ РїРѕРїС‹С‚РєР° РїРѕРІС‚РѕСЂРЅРѕ Р·Р°СЂРµРіРёСЃС‚СЂРёСЂРѕРІР°С‚СЊ С‚РѕС‚ Р¶Рµ request СЃ РґСЂСѓРіРёРј digest, РёСЃРїРѕР»СЊР·РѕРІР°С‚СЊ request РїРѕСЃР»Рµ СЃРјРµРЅС‹ trusted checkout SHA, РїРѕРґРјРµРЅРёС‚СЊ РґРѕРєСѓРјРµРЅС‚С‹ РёР»Рё СЂР°Р·СЂРµС€РёС‚СЊ РЅРµРёР·РІРµСЃС‚РЅС‹Р№ request/code РѕС‚РєР»РѕРЅСЏРµС‚СЃСЏ РґРѕ gate/issuer.

Adapter РЅРµ РІС‹РїРѕР»РЅСЏРµС‚ Р·Р°РїРёСЃСЊ, promotion РёР»Рё queue dispatch; replay/uniqueness С…СЂР°РЅРёС‚СЃСЏ Рё РїСЂРѕРІРµСЂСЏРµС‚СЃСЏ РЅР° persistence boundary РѕС‚РґРµР»СЊРЅС‹Рј release registry.

## Dependency composition

Service provider РѕР±СЏР·Р°РЅ СЃРІСЏР·Р°С‚СЊ `ReportPublicationReleaseRequestRegistry -> FilesystemReportPublicationReleaseComposition -> (candidate resolver, factory, binding map, evidence repository, eligibility gate)`.

CLI `issue-report-publication-release.php` РїРѕР»СѓС‡Р°РµС‚ trusted root Рё request path РёР· workflow; CLI РЅРµ СЃРѕР·РґР°С‘С‚ registry С‡РµСЂРµР· `new` Р±РµР· Р·Р°РІРёСЃРёРјРѕСЃС‚РµР№ Рё РЅРµ С‡РёС‚Р°РµС‚ РїСЂРѕРёР·РІРѕР»СЊРЅС‹Рµ РєР°С‚Р°Р»РѕРіРё. Р•СЃР»Рё РєРѕРЅС„РёРіСѓСЂР°С†РёСЏ РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚, РєРѕРјР°РЅРґР° Р·Р°РІРµСЂС€Р°РµС‚СЃСЏ РґРѕ С‡С‚РµРЅРёСЏ Р°СЂС‚РµС„Р°РєС‚РѕРІ.

## Test matrix (РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Р№ РґРѕ СЂРµР°Р»РёР·Р°С†РёРё)

1. Valid canonical request: РїРѕР»РЅС‹Р№ production composition РІРѕР·РІСЂР°С‰Р°РµС‚ admission Рё gate; hashes/checks СЃРѕРІРїР°РґР°СЋС‚.
2. Missing request/artifact: fail closed.
3. Wrong commit РІ candidate/conformance/proof/provenance: fail closed.
4. Tampered bytes РїСЂРё СЃРѕС…СЂР°РЅС‘РЅРЅРѕРј JSON: canonical/hash mismatch.
5. Symlink Рё traversal: fail closed.
6. Unknown/unregistered request id Рё wrong code: fail closed.
7. Replay СЃ С‚РµРј Р¶Рµ digest: deterministic re-resolution; replay СЃ РёРЅС‹Рј digest: reject.
8. Binding РѕС‚СЃСѓС‚СЃС‚РІСѓРµС‚ РёР»Рё definition hash РѕС‚Р»РёС‡Р°РµС‚СЃСЏ: reject.
9. Evidence failed/missing check/test component: reject; production component РѕР±СЏР·Р°С‚РµР»РµРЅ.
10. Production composition boundary: test classes Рё CI fixture adapters cannot be injected.

Р”Рѕ РїСЂРѕС…РѕР¶РґРµРЅРёСЏ РІСЃРµР№ РјР°С‚СЂРёС†С‹ РїСѓР±Р»РёРєР°С†РёСЏ РѕС‚С‡С‘С‚Р° РЅРµ РѕР±СЉСЏРІР»СЏРµС‚СЃСЏ РіРѕС‚РѕРІРѕР№.
## РЈС‚РѕС‡РЅРµРЅРёСЏ РїРѕСЃР»Рµ РЅРµР·Р°РІРёСЃРёРјРѕРіРѕ СЂРµРІСЊСЋ

### Trusted checkout SHA Рё layout

РћР¶РёРґР°РµРјС‹Р№ SHA checkout СЏРІР»СЏРµС‚СЃСЏ РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Рј С‚РёРїРёР·РёСЂРѕРІР°РЅРЅС‹Рј Р°СЂРіСѓРјРµРЅС‚РѕРј composition: compose(request, TrustedReleaseDirectory, CommitSha expectedCommitSha). CommitSha РїСЂРёРЅРёРјР°РµС‚ С‚РѕР»СЊРєРѕ 40 СЃРёРјРІРѕР»РѕРІ lower-case hexadecimal. Р—РЅР°С‡РµРЅРёРµ РЅРµР»СЊР·СЏ РїРѕР»СѓС‡Р°С‚СЊ РёР· request, РѕРєСЂСѓР¶РµРЅРёСЏ РёР»Рё СЃРѕРґРµСЂР¶РёРјРѕРіРѕ Р°СЂС‚РµС„Р°РєС‚Р°: workflow РїРµСЂРµРґР°С‘С‚ SHA РїСЂРѕРІРµСЂРµРЅРЅРѕРіРѕ checkout РѕС‚РґРµР»СЊРЅРѕ.

TrustedReleaseDirectory СЃС‚СЂРѕРёС‚СЃСЏ С‚РѕР»СЊРєРѕ РёР· realpath(config('reports.publication_release.trusted_directory')). CLI РїСЂРѕРІРµСЂСЏРµС‚, С‡С‚Рѕ request path СЏРІР»СЏРµС‚СЃСЏ РїСЂСЏРјС‹Рј child СЌС‚РѕРіРѕ root, Р·Р°С‚РµРј ReportPublicationReleaseRequestFileLoader::load(requestPath, root) РїСЂРѕРІРµСЂСЏРµС‚ extension, symlink, canonical JSON Рё РёРјСЏ {request_id}.json. Composition РІС‹РІРѕРґРёС‚ Р°СЂС‚РµС„Р°РєС‚С‹ С‚РѕР»СЊРєРѕ РёР· С„РёРєСЃРёСЂРѕРІР°РЅРЅРѕРіРѕ artifact_paths request Рё С‚СЂРµР±СѓРµС‚, С‡С‚РѕР±С‹ РєР°Р¶РґС‹Р№ РїСѓС‚СЊ Р±С‹Р» РїСЂСЏРјС‹Рј child С‚РѕРіРѕ Р¶Рµ root; РєР°С‚Р°Р»РѕРі РЅРµ РґРѕР»Р¶РµРЅ СЃРѕРґРµСЂР¶Р°С‚СЊ Р°Р»СЊС‚РµСЂРЅР°С‚РёРІРЅС‹С… РёРјС‘РЅ РёР»Рё РїСЂРѕРёР·РІРѕР»СЊРЅС‹С… РїРѕРґРєР°С‚Р°Р»РѕРіРѕРІ. Request-С„Р°Р№Р» РЅРµ СЃС‡РёС‚Р°РµС‚СЃСЏ Р°СЂС‚РµС„Р°РєС‚РѕРј.

### РўРѕС‡РЅС‹Рµ lookup signatures Рё ownership

- CandidateReportDefinitionRegistry::candidate(string $code): CandidateReportDefinition вЂ” authoritative candidate lookup; adapter РїСЂРѕРІРµСЂСЏРµС‚ code Рё definition hash.
- ReportDefinitionBindingAssembler::assemble(ReportDefinitionRegistry $registry): ReportDefinitionBindingMap вЂ” РµРґРёРЅСЃС‚РІРµРЅРЅС‹Р№ РІР»Р°РґРµР»РµС† production binding composition; map lookup РїСЂРѕРІРµСЂСЏРµС‚ definition hash.
- FilesystemReportConformanceEvidenceRepository::get(string $code, Sha256Hash $definitionHash, Sha256Hash $fixtureHash): ReportDefinitionConformanceEvidence; adapter derives both hashes from candidate/evidence manifest and verifies returned code/hash values вЂ” РµРґРёРЅСЃС‚РІРµРЅРЅС‹Р№ РІР»Р°РґРµР»РµС† evidence hydrate.
- ReportPublicationProof::fromArray(array $payload): ReportPublicationProof вЂ” РµРґРёРЅСЃС‚РІРµРЅРЅС‹Р№ parser proof.
- ReportPublicationAdmissionRequirements::requiredChecks(string $code): array вЂ” authoritative verifiedChecks; adapter РЅРµ РїСЂРёРЅРёРјР°РµС‚ caller-provided СЃРїРёСЃРѕРє Рё СЃРѕС…СЂР°РЅСЏРµС‚ РїРѕСЂСЏРґРѕРє proof.ci.required_checks.

РџРµСЂРµРґ РІРѕР·РІСЂР°С‚РѕРј admission adapter СЃСЂР°РІРЅРёРІР°РµС‚ candidate, binding, evidence Рё proof definition hashes.

### Replay port Рё Р°С‚РѕРјР°СЂРЅР°СЏ СѓРЅРёРєР°Р»СЊРЅРѕСЃС‚СЊ

Replay С‚СЂРµР±СѓРµС‚ РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Р№ РїРѕСЂС‚ ReportPublicationReleaseReplayStore::reserveOrMatch(ReportPublicationReleaseIdempotencyKey $key): ReplayReservation.

ReportPublicationReleaseIdempotencyKey вЂ” immutable value object РёР· requestId, code, commitSha, proofSha256; canonical digest РёСЃРїРѕР»СЊР·СѓРµС‚СЃСЏ РєР°Рє СѓРЅРёРєР°Р»СЊРЅС‹Р№ РєР»СЋС‡. reserveOrMatch Р°С‚РѕРјР°СЂРµРЅ: РѕС‚СЃСѓС‚СЃС‚РІСѓСЋС‰РёР№ РєР»СЋС‡ СЂРµР·РµСЂРІРёСЂСѓРµС‚СЃСЏ; СЃСѓС‰РµСЃС‚РІСѓСЋС‰РёР№ СЃ С‚РµРј Р¶Рµ digest РІРѕР·РІСЂР°С‰Р°РµС‚ matched; РёРЅРѕР№ digest/commit РІРѕР·РІСЂР°С‰Р°РµС‚ conflict. Adapter РЅРµ РїСЂРѕРґРІРёРіР°РµС‚ РїСѓР±Р»РёРєР°С†РёСЋ Рё РЅРµ РїРёС€РµС‚ С‡Р°СЃС‚РёС‡РЅС‹Рµ Р·Р°РїРёСЃРё РґРѕ РїРѕР»РЅРѕР№ РІРµСЂРёС„РёРєР°С†РёРё.

Owner replay-РєРѕРЅС‚СЂР°РєС‚Р° вЂ” infrastructure release registry. РўРµСЃС‚С‹-РІР»Р°РґРµР»СЊС†С‹: tests/Unit/Reporting/Publication/ReportPublicationReleaseReplayStoreTest.php Рё tests/Feature/Reporting/Publication/ReportPublicationReleaseCompositionTest.php. РњР°С‚СЂРёС†Р°: first reserve, same-key match, digest conflict, commit conflict Рё atomic concurrent reservation С‡РµСЂРµР· deterministic in-memory contract double.

