# Vision Luna Diagnostic Hotfix Implementation Plan

1. Add failing provider tests for OpenAI and unknown error envelopes, malformed/oversized bodies, redaction, replay, HTTP classification and zero usage.
2. Add failing PostgreSQL contracts for a two-worker document breaker, attempt/source/fingerprint isolation and `breaker_stopped` persistence.
3. Add failing workflow tests for a zero-ready system failure while a session is in document review.
4. Implement bounded error inspection and safe typed diagnostics without changing the Luna request payload.
5. Propagate the stable diagnostic fingerprint through failure normalization and enable the Vision terminal breaker.
6. Make document reconciliation transition system failures to a resumable failed session and preserve honest progress/readiness.
7. Run focused verification, independent review, then the standard release and read-only canary.
