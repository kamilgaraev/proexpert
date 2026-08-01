# R15: positive resolver fixture pipeline

Положительный тест `ProcurementCycleReleaseCandidateResolver` пока не может
использовать существующий `R15CiConformanceEvidenceGenerator`: генератор
создаёт только conformance artifact и запускается только в CI composition.
`R15CiRuntimeFixtureFactory` возвращает runtime DTO и не является источником
полного release bundle.

Для закрытия этого тестового пробела нужен отдельный builder (владение:
Reporting/Procurement):

1. построить `candidate_definition` через `ReportDefinitionBuilder` и записать
   canonical `r15-candidate-manifest.json`;
2. получить production binding и conformance evidence через
   `R15CiConformanceEvidenceGenerator`, затем записать canonical
   `r15-conformance-evidence.json` с digest и commit SHA;
3. собрать полный `r15-proof-template.json` по
   `r15-candidate-proof-template.v1.schema.json`, включая binding/evidence,
   source/formula/export/drill-down/CI/release hashes;
4. вычислить proof digest и записать `r15_release_request.json` по
   `r15-publication-request.v1.schema.json` с фиксированными путями;
5. в одном E2E-тесте вызвать resolver и проверить acceptance, затем отдельными
   тестами изменить один байт conformance, удалить request и изменить commit;
   каждый сценарий обязан завершаться `r15_release_candidate_untrusted`.

До появления этого builder hand-crafted fixture не считается положительным
доказательством: текущий legacy fixture намеренно проверяется как rejected.
