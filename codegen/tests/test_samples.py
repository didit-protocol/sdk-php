"""Sample values of the generated tests."""

from __future__ import annotations

import unittest

import helpers  # noqa: F401  (import path)
from didit_codegen.samples import scrub_credentials


class ScrubCredentialsTest(unittest.TestCase):
    def test_credential_keys_get_placeholders(self) -> None:
        value = {
            "api_key": "abc123",
            "client_secret": "s3cr3t",
            "access_token": "opaque",
            "password": "hunter2",
            "secret_shared_key": "k",
            "name": "Jane",
        }
        self.assertEqual(scrub_credentials(value), {
            "api_key": "sample-api-key",
            "client_secret": "sample-client-secret",
            "access_token": "sample-access-token",
            "password": "sample-password",
            "secret_shared_key": "sample-secret-shared-key",
            "name": "Jane",
        })

    def test_jwts_are_replaced_anywhere(self) -> None:
        jwt = "eyJhbGciOiJFUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl"
        self.assertEqual(scrub_credentials({"note": jwt}), {"note": "sample-jwt"})
        self.assertEqual(scrub_credentials([jwt]), ["sample-jwt"])

    def test_nested_and_list_values_keep_the_key(self) -> None:
        value = {"results": [{"refresh_token": "r1"}], "tokens": ["a"], "id_token": ["x", "y"]}
        self.assertEqual(scrub_credentials(value), {
            "results": [{"refresh_token": "sample-refresh-token"}],
            "tokens": ["a"],
            "id_token": ["sample-id-token", "sample-id-token"],
        })

    def test_other_values_are_untouched(self) -> None:
        value = {"token": "", "secret": 12, "api_key": None, "tokenizer": "bpe", "status": "Approved"}
        self.assertEqual(scrub_credentials(value), value)
        self.assertEqual(scrub_credentials("plain"), "plain")


if __name__ == "__main__":
    unittest.main()
