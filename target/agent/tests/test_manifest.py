"""Tests for manifest parser + schema validation."""
from __future__ import annotations

import json
import textwrap

import pytest

from src.manifest import Manifest, ManifestError


VALID = {
    "schema_version": 1,
    "challenge_id": "DEMO-001",
    "version": 1,
    "type": "web",
    "difficulty": "easy",
    "title": "Demo Challenge",
    "entrypoint": "/challenge/DEMO-001/",
    "verification": {"type": "flag", "flag_static": "flag{abc123}"},
}


def test_valid_manifest_parses():
    m = Manifest.from_dict(VALID)
    assert m.challenge_id == "DEMO-001"
    assert m.version == 1
    assert m.verification.type == "flag"
    assert m.verification.flag_static == "flag{abc123}"


def test_missing_required_field():
    bad = dict(VALID)
    del bad["challenge_id"]
    with pytest.raises(ManifestError, match="challenge_id"):
        Manifest.from_dict(bad)


def test_unsupported_schema_version():
    bad = dict(VALID, schema_version=99)
    with pytest.raises(ManifestError, match="schema_version"):
        Manifest.from_dict(bad)


def test_invalid_type():
    bad = dict(VALID, type="quantum")
    with pytest.raises(ManifestError, match="type"):
        Manifest.from_dict(bad)


def test_invalid_difficulty():
    bad = dict(VALID, difficulty="trivial")
    with pytest.raises(ManifestError, match="difficulty"):
        Manifest.from_dict(bad)


def test_flag_verification_requires_flag_static():
    bad = dict(VALID)
    bad["verification"] = {"type": "flag"}
    with pytest.raises(ManifestError, match="flag_static"):
        Manifest.from_dict(bad)


def test_automatic_verification_requires_script():
    bad = dict(VALID)
    bad["verification"] = {"type": "automatic"}
    with pytest.raises(ManifestError, match="automatic"):
        Manifest.from_dict(bad)


def test_automatic_verification_with_script():
    good = dict(VALID)
    good["verification"] = {"type": "automatic", "automatic": {"script": "verifier/check.sh"}}
    m = Manifest.from_dict(good)
    assert m.verification.type == "automatic"
    assert m.verification.automatic_script == "verifier/check.sh"


def test_optional_database_block():
    good = dict(VALID)
    good["database"] = {"name": "ctf_demo_001", "setup_sql": "setup.sql"}
    m = Manifest.from_dict(good)
    assert m.database is not None
    assert m.database.name == "ctf_demo_001"


def test_optional_reset_block():
    good = dict(VALID)
    good["reset"] = {"drop_and_recreate_db": True, "restore_files": ["web/"]}
    m = Manifest.from_dict(good)
    assert m.reset is not None
    assert m.reset.drop_and_recreate_db is True
    assert m.reset.restore_files == ["web/"]


def test_manifest_load_from_file(tmp_path):
    p = tmp_path / "manifest.json"
    p.write_text(json.dumps(VALID), encoding="utf-8")
    m = Manifest.load(str(p))
    assert m.challenge_id == "DEMO-001"


def test_manifest_load_missing_file(tmp_path):
    with pytest.raises(ManifestError, match="not found"):
        Manifest.load(str(tmp_path / "missing.json"))


def test_manifest_load_invalid_json(tmp_path):
    p = tmp_path / "manifest.json"
    p.write_text("not valid json{", encoding="utf-8")
    with pytest.raises(ManifestError, match="failed to read"):
        Manifest.load(str(p))


def test_version_must_be_positive_int():
    bad = dict(VALID, version=0)
    with pytest.raises(ManifestError, match="version"):
        Manifest.from_dict(bad)
    bad2 = dict(VALID, version=-1)
    with pytest.raises(ManifestError, match="version"):
        Manifest.from_dict(bad2)
    bad3 = dict(VALID, version="1")  # string, not int
    with pytest.raises(ManifestError, match="version"):
        Manifest.from_dict(bad3)
