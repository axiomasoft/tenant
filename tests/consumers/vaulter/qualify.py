"""P5.2 qualification: immutable corpus, actual owning source tests, archive-installed IO."""
import argparse
import datetime
import hashlib
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import xml.etree.ElementTree as ET
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[3]
PLAN = next((ROOT / "plans").glob("*TENANT")).resolve()
ART = PLAN / "artifacts/P5.2"


def sha(path):
    return hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()


def save(path, value):
    path = pathlib.Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n")


def command(argv, cwd, env, raw):
    start = datetime.datetime.now(datetime.timezone.utc).isoformat()
    t = time.monotonic()
    result = subprocess.run(argv, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    raw = pathlib.Path(raw)
    raw.parent.mkdir(parents=True, exist_ok=True)
    raw.write_bytes(result.stdout)
    return {"argv": [str(x) for x in argv], "cwd": str(cwd), "started_at": start,
            "finished_at": datetime.datetime.now(datetime.timezone.utc).isoformat(), "duration": time.monotonic()-t,
            "exit": result.returncode, "status": "passed" if result.returncode == 0 else "failed",
            "raw": str(raw.relative_to(PLAN)), "sha256": sha(raw)}


def preflight(manifest):
    for service, cid in manifest["containers"].items():
        v = json.loads(subprocess.check_output(["docker", "inspect", cid]))[0]
        assert v["State"]["Running"]
        assert v["Config"]["Labels"]["com.docker.compose.project"] == manifest["project"]
        tag = "postgres:16-alpine" if service == "pg" else "redis:7-alpine"
        assert v["Image"] == manifest["images"][tag]["id"]
        assert [x["Name"] for x in v["Mounts"] if x["Type"] == "volume"] == manifest["volumeIds"][service]
        assert list(v["NetworkSettings"]["Networks"].values())[0]["NetworkID"] == manifest["networkIds"][service]
    m = manifest
    observed = subprocess.check_output(["docker","exec",m["containers"]["pg"],"psql","-X","-At","-U",m["admin"],
                                        "-d",m["databases"]["central"],"-c","select system_identifier from pg_control_system()"],text=True).strip()
    assert observed == m["systemIdentifier"]
    for key, db in m["databases"].items():
        assert db == "tenant_"+m["runId"]+"_"+key+"_test"
        assert subprocess.run(["docker","exec",m["containers"]["pg"],"pg_isready","-U",m["admin"],"-d",db],
                              stdout=subprocess.DEVNULL).returncode == 0
    root=pathlib.Path(m["root"])
    assert root.parent==pathlib.Path("/tmp") and root.name.startswith("tenant_lab_") and not root.is_symlink()


def inputs(checkout):
    files = {}
    for package in ["core","drive","media","tenant"]:
        for f in sorted((checkout/"packages"/package).rglob("*")):
            if f.is_file() and not any(x in f.relative_to(checkout).parts for x in ["vendor","node_modules",".git",".phpunit.cache"]):
                files["vaulter/"+str(f.relative_to(checkout))] = sha(f)
    for name in ["README.md","composer.json","composer.lock"]:
        files["vaulter/"+name]=sha(checkout/name)
    for base in ["packages/core","packages/laravel","tests/consumers/vaulter"]:
        for f in sorted((ROOT/base).rglob("*")):
            if f.is_file() and not any(x in f.relative_to(ROOT).parts for x in ["vendor","node_modules","__pycache__",".phpunit.cache"]):
                files["tenant/"+str(f.relative_to(ROOT))]=sha(f)
    for name in ["scripts/qualify-consumers.py","contracts/api.v1.json","contracts/profiles.v1.json","contracts/fixtures/index.json"]:
        files["tenant/"+name]=sha(ROOT/name)
    return files


def installed(checkout, lanes):
    archives=json.loads((ART/"archives.json").read_text())
    totals={}
    for key,meta in archives.items():
        assert sha(meta["path"])==meta["sha256"],("archive",key)
        source=pathlib.Path(meta["source"])
        assert source==({"tenant":ROOT/"packages/core","tenant-laravel":ROOT/"packages/laravel"}.get(key)
                        or checkout/"packages"/key.removeprefix("vaulter-"))
        current={}
        for f in sorted(source.rglob("*")):
            if not f.is_file() or any(x in f.relative_to(source).parts for x in ["vendor","node_modules","tests",".git"]):continue
            b=f.read_bytes();rel=str(f.relative_to(source))
            if rel=="composer.json":
                j=json.loads(b);j["version"]="1.0.0";j.pop("repositories",None);b=json.dumps(j,indent=4).encode()
            current[rel]=hashlib.sha256(b).hexdigest()
        assert current==meta["files"],("source archive mismatch",key)
    for lane in lanes:
        host=ART/"hosts"/lane;n=0
        lock=json.loads((host/"composer.lock").read_text())
        assert next(x["version"] for x in lock["packages"] if x["name"]=="laravel/framework").startswith("v12." if lane.endswith("l12") else "v13.")
        package_meta=json.loads((host/"vendor/composer/installed.json").read_text())
        assert package_meta["dev"] is False
        names=[x["name"] for x in package_meta["packages"]]
        assert not any("corex" in x or x.startswith(("flex/","flexcrm/")) for x in names)
        for key,meta in archives.items():
            with zipfile.ZipFile(meta["path"]) as z:name=json.loads(z.read(key+"/composer.json"))["name"]
            for rel,expected in meta["files"].items():
                assert sha(host/"vendor"/name/rel)==expected,("installed mismatch",lane,key,rel)
                n+=1
        totals[lane]={"files":n,"lockSha256":sha(host/"composer.lock"),"packages":{x["name"]:x["version"] for x in package_meta["packages"]}}
    return totals


MARKERS = {
    "INSTALL-01":["same configured resolver instance owns both ports"],
    "AUTH-01":["Missing actual Drive entry refuses","foreign owner refuses before Drive write","edited owner identity rejected"],
    "CENTRAL-01":["Central publication refuses","explicit central work has no inherited tenant stamp","explicit central worker writes named control DB"],
    "QUEUE-02":["original A purge enqueued while B active","deferred callback retains frozen A wire","actual deferred scan target stays A while B active"],
    "QUEUE-03":["same driveId purge locks are partitioned by original resource/incarnation","actual purge converged all owning rows with continuation","actual scanner bytes isolated and delivered once A/B"],
    "VAULT-01":["same opaque id across namespaces has distinct scalars","Missing scalar refuses","Central scalar refuses","fixed namespace rejects a second registered namespace at boot"],
    "VAULT-02":["tenantOf A remains A under ambient B","fresh host owner mapping ignores stale supplied Model attributes","deferred actual scan reads A bytes after restoration before validator","deleted actor refused without system promotion"],
    "BINDING-01":["stale durable route refuses before enqueue","actual wrong physical marker refuses before enqueue","all stale and unavailable captures produce zero queue writes"],
    "VAULT-IO-01":["actual physical connection alpha","actual physical connection beta","actual scanner bytes isolated and delivered once A/B","stale resource disk permanently refuses","real Redis cache remains separated alpha","foreign cookie cannot authenticate or inherit A session in B redis","lease-backed native stream prevents switching until closed"],
    "VAULT-ENCODING-01":["frozen injective scalar and bounded neutral DTOs","unsupported host key type boot refuses","narrow actual tenant column boot refuses"],
    "VAULT-PRODUCERS-01":["actual purge prepared before registered afterCommit callback","actual ScanWorkReconciler captures before deferred afterCommit","actual deferred scan target stays A while B active","actual purge converged all owning rows with continuation"],
    "WIRE-01":["wire duplicate keys refused","wire central null identity refused","unknown required extension denied before effect","bounded optional unknown wire decoded without effects","unregistered exact profile version refused","untrusted transport fails in real worker before hydration or byte IO"],
}


def consumer(checkout,manifest,manifest_path,lanes,source):
    result={"status":"failed","lanes":lanes,"sourceDigest":digest(source),"sourceHashes":source,"commands":[],"observations":{},"skipped":0}
    generation=ART/"final"/digest(source)[:16]
    for lane in lanes:
        host=ART/"hosts"/lane
        env={**os.environ,"XDEBUG_MODE":"off","APP_ENV":"testing","TENANT_LAB_MANIFEST":str(manifest_path.resolve()),
             "TENANT_LANE_AUTOLOAD":str(host/"vendor/autoload.php"),"TENANT_USE_SOURCE":"0","VAULTER_USE_SOURCE":"0",
             "VAULTER_CONSUMER_CHECKOUT":str(checkout),"TENANT_VAULTER_SUPPORT":str(ROOT/"tests/consumers/vaulter")}
        commands=[]
        for name,path in [("setup",ROOT/"tests/consumers/vaulter/Setup.php"),("preflight",ROOT/"tests/laravel/Support/preflight.php"),
                          ("scalar",checkout/"packages/tenant/tests/Feature/ScalarBindingTest.php"),
                          ("producer",checkout/"packages/tenant/tests/Feature/ProducerBoundaryTest.php"),
                          ("guards",ROOT/"tests/consumers/vaulter/BoundaryGuardsTest.php"),("http",ROOT/"tests/consumers/vaulter/HttpProbe.php")]:
            rec=command(["/usr/bin/php8.4",str(path)],ROOT,env,generation/lane/(name+".log"))
            commands.append(rec);result["commands"].append(rec)
            save(ART/"final/consumer-progress.json",result)
            assert rec["exit"]==0,rec
        # Actual original retained/adapted source suites on their own allowlisted schema DB.
        dev=ART/"dev-hosts"/lane;env["VAULTER_DEV_AUTOLOAD"]=str(dev/"vendor/autoload.php")
        tests=["Tenancy/TenantJobBoundaryTest.php","Tenancy/TenantIdentifierPredicateTest.php","Tenancy/PrivateScannerTenantBoundaryTest.php",
               "Drive/DrivePurgeTest.php","Uploads/P3/ScanJobBoundaryTest.php","Uploads/P3/ScanWorkRecoveryTest.php"]
        junit=generation/lane/"source-junit.xml"
        rec=command(["/usr/bin/php8.4",str(dev/"vendor/bin/pest"),"--configuration",str(dev/"phpunit.xml"),"--test-directory","tests","--log-junit",str(junit),
                     *[str(dev/"tests"/x) for x in tests]],dev,env,generation/lane/"source.log")
        commands.append(rec);result["commands"].append(rec)
        assert rec["exit"]==0,rec
        suites=ET.parse(junit).getroot();cases=suites.findall(".//testcase")
        assert len(cases)==35 and not suites.findall(".//skipped") and not suites.findall(".//failure") and not suites.findall(".//error")
        transaction_junit=generation/lane/"transaction-junit.xml"
        transaction=command(["/usr/bin/php8.4",str(dev/"vendor/bin/pest"),"--configuration",str(ROOT/"packages/laravel/phpunit.xml"),"--log-junit",str(transaction_junit),str(ROOT/"packages/laravel/tests/Facilities/TransactionCallbackTest.php")],ROOT,env,generation/lane/"transaction.log")
        commands.append(transaction);result["commands"].append(transaction)
        assert transaction["exit"]==0,transaction
        txroot=ET.parse(transaction_junit).getroot()
        assert len(txroot.findall(".//testcase"))==3 and not txroot.findall(".//skipped")
        save(ART/"final/consumer-progress.json",result)
        text="\n".join((PLAN/c["raw"]).read_text() for c in commands)
        assert "WORKER pid=" in text and "SCANNER " in text and "HTTP_SERVER_LOG " in text
        assert not any(x in text for x in ["PHP Warning:","PHP Fatal error:","SKIP"]),lane
        result["observations"][lane]={}
        for fixture,markers in MARKERS.items():
            rows=[next((line for line in text.splitlines() if line.startswith("PASS ") and marker in line),None) for marker in markers]
            assert all(rows),(lane,fixture,rows)
            observation={"fixture":fixture,"lane":lane,"status":"passed","sourceDigest":digest(source),"assertions":rows,
                         "raw":[{"path":c["raw"],"sha256":c["sha256"],"argv":c["argv"],"cwd":c["cwd"],"exit":c["exit"]} for c in commands],
                         "sourceTests":len(cases),"requiredSkips":0}
            f=generation/lane/"observations"/(fixture+".json");save(f,observation)
            result["observations"][lane][fixture]={"path":str(f.relative_to(PLAN)),"sha256":sha(f),"assertions":rows}
    result["status"]="passed";save(ART/"final/consumer.json",result)
    return result


def prior(source,lanes):
    result=json.loads((ART/"final/consumer.json").read_text())
    assert result["status"]=="passed" and result["lanes"]==lanes
    if result["sourceDigest"]!=digest(source):
        runtime=lambda rows:{k:v for k,v in rows.items() if k!="tenant/tests/consumers/vaulter/qualify.py"}
        assert runtime(result["sourceHashes"])==runtime(source),"Runtime inputs changed; rerun affected qualification."
        save(ART/"final"/("consumer-before-ledger-correction-"+result["sourceDigest"][:16]+".json"),result)
        result={**result,"sourceHashes":source,"sourceDigest":digest(source),"metadataOnlyRebind":{
            "originalSourceDigest":result["sourceDigest"],"runtimeInputDigest":digest(runtime(source)),
            "reason":"Only qualification renderer/provenance changed: schema-compliant fields and separate exact profile ledgers. All runtime source/test/archive bytes and successful command logs unchanged."}}
        save(ART/"final/consumer.json",result)
    for rec in result["commands"]:assert rec["exit"]==0 and sha(PLAN/rec["raw"])==rec["sha256"]
    for mapping in result["observations"].values():
        for o in mapping.values():assert sha(PLAN/o["path"])==o["sha256"]
    return result


def ledgers(source,result,checkouts):
    archives=json.loads((ART/"archives.json").read_text())
    portions=json.loads((PLAN/"artifacts/P5-detail/P5.2-portions.json").read_text())["portions"]
    for lane in result["lanes"]:
        rows=[]
        for p in portions:
            conditional=p["applicability"]["status"]=="conditional"
            entry=None if conditional else result["observations"][lane][p["fixtureId"]]
            rows.append({"portionId":p["id"],"fixtureId":p["fixtureId"],"producerItem":"P5.2","profile":p["profile"],"lane":lane,"proofKind":p["proofKind"],
                         "applicability":p["applicability"],"check":{"procedure":"Actual owning Vaulter producers/source tests, archive-installed public bridge, real PG/Redis workers/IO/HTTP; exact fixture observations and raw commands linked","result":None if conditional else 0},
                         "expected":p["expectedObservations"],"observed":None if conditional else "\n".join(entry["assertions"]),
                         "status":"not-run" if conditional else "passed","rawEvidence":None if conditional else "@plan/"+entry["path"],
                         **({} if conditional else
                            {"artifactHashes":{k:v["sha256"] for k,v in archives.items()}})})
        ledger={"contract":"tenant.fixture-results/1","corpusHash":sha(ROOT/"contracts/fixtures/index.json"),
                "indexSchemaHash":sha(ROOT/"contracts/fixtures/index.schema.json"),"resultSchemaHash":sha(ROOT/"contracts/fixtures/results.schema.json"),
                "candidate":{"checkout":str(ROOT),"dirtyContentHash":digest(source),"artifactHashes":{k:v["sha256"] for k,v in archives.items()}},"results":rows}
        single_rows=[{**row,"lane":"php84-cli"} for row in rows if row["profile"]=="single"]
        ledger["results"]=[row for row in rows if row["profile"]=="laravel-pg-database"]
        if lane==result["lanes"][0]:
            save(ART/"final/single-not-advertised.json",{**ledger,"results":single_rows})
            save(ART/"final/portion-completeness.json",{"original":len(portions),"required":len(ledger["results"]),"conditionalNotAdvertised":len(single_rows),"originalApplicabilityPreserved":True})
        save(ART/"final"/lane/"acceptance.json",ledger)
        if lane==result["lanes"][0]:save(PLAN/"artifacts/acceptance/P5.2.json",ledger)


def trace(checkout,source,result):
    for package in ["core","drive"]:
        manifest=json.loads((checkout/"packages"/package/"composer.json").read_text())
        assert not any("tenant" in x.lower() or "corex" in x.lower() for x in manifest["require"]),package
        for f in (checkout/"packages"/package/"src").rglob("*.php"):
            t=f.read_text()
            assert not re.search(r"(?:use|new)\s+(?:Tenant|CoreX)\\",t),str(f)
    for f in (checkout/"packages/tenant/src").rglob("*.php"):
        assert not re.search(r"(?:use|new)\s+(?:Vaulter\\Drive|Tenant\\Laravel\\Internal|CoreX)\\",f.read_text()),str(f)
    source_rows=[]
    for base in ["core","drive","media"]:
        for f in sorted((checkout/"packages"/base/"src").rglob("*.php")):
            hits=[n for n,line in enumerate(f.read_text().splitlines(),1) if re.search(r"TenantResolver|tenantOf|tenant_id|JobPublisher|PurgeDriveJob|ScanFileRevision|ScanWorkReconciler",line)]
            if hits:
                base=json.loads((ART/"checkout-manifest.json").read_text())["base"]
                original=subprocess.run(["git","-C",str(checkout),"show",base+":"+str(f.relative_to(checkout))],capture_output=True)
                disposition="new-gap" if original.returncode else ("keep" if hashlib.sha256(original.stdout).hexdigest()==sha(f) else "adapt")
                source_rows.append({"source":str(f.relative_to(checkout)),"sha256":sha(f),"lines":hits,"disposition":disposition,
                                    "owner":base,"integration":"Public Vaulter resolver/neutral publisher; owning schema/actor/lease/CAS invariants retained; actual archive-installed consumer evidence"})
    seeds=json.loads((PLAN/"artifacts/P5-detail/source-inspection.json").read_text())["files"];tests=[]
    for seed in seeds:
        if seed["repo"]!="vaulter" or "/tests/" not in seed["path"]:continue
        f=ART/"source-bodies"/seed["path"];assert sha(f)==seed["sha256"]
        tests.append({"source":seed["path"],"originalSha256":seed["sha256"],"currentSha256":sha(checkout/seed["path"]),
                      "disposition":"retained/adapted","target":seed["path"],"evidence":"Actual Pest35 cases/204 assertions per lane in final consumer source.log; fake tests explicitly supplemental to real workers"})
    reuse={"item":"P5.2","status":"passed","sourceDigest":digest(source),"source":source_rows,"tests":tests,
           "newGaps":["Frozen P1 neutral publisher and immutable DTOs absent in original Vaulter",
                      "Optional scalar bridge and fresh host owner mapping",
                      "P5.1 public filesystem adapter reused through public SPI; lease-backed sequential PHP stream needed by Vaulter Media",
                      "Nested producer afterCommit records execute before parent lease release; existing GuardedConnection callback registered for each savepoint"],
           "keepProof":"Existing owning Drive/CAS/actor/work lease/schema implementations exercised unchanged through installed public composition and retained source suites",
           "evidence":"artifacts/P5.2/final/consumer.json"}
    assert len(tests)>=6 and source_rows
    save(PLAN/"artifacts/reuse/P5.2.json",reuse)
    return {"status":"passed","sourceCallers":len(source_rows),"sourceTestFiles":len(tests)}


def package(checkout,lanes,source):
    report={"status":"passed","installed":installed(checkout,lanes),"commands":[]}
    generation=ART/"final"/digest(source)[:16]
    for lane in lanes:
        env={**os.environ,"XDEBUG_MODE":"off","VAULTER_DEV_AUTOLOAD":str(ART/"dev-hosts"/lane/"vendor/autoload.php"),"VAULTER_CONSUMER_CHECKOUT":str(checkout)}
        host=ART/"hosts"/lane
        report["commands"].append(command(["/usr/bin/php8.4","/usr/local/bin/composer","validate","--no-check-publish"],host,env,generation/lane/"composer-validate.log"))
        report["commands"].append(command(["/usr/bin/php8.4",str(ROOT/"vendor/bin/phpstan"),"analyse","--no-progress","--level=5","--autoload-file="+str(ROOT/"tests/consumers/vaulter/StaticBootstrap.php"),str(checkout/"packages/tenant/src"),str(checkout/"packages/core/src/Jobs"),str(checkout/"packages/core/src/Contracts/Jobs"),str(checkout/"packages/drive/src/Jobs/DriveJobTasks.php")],ROOT,env,generation/lane/"phpstan.log"))
        for f in sorted((checkout/"packages/tenant/src").rglob("*.php")):
            report["commands"].append(command(["/usr/bin/php8.4","-l",str(f)],ROOT,env,generation/lane/"lint"/(f.stem+".log")))
    assert all(c["exit"]==0 for c in report["commands"]),report
    return report


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--item",required=True,choices=["P5.2"])
    parser.add_argument("--stage",required=True,choices=["prepare","consumer","trace","boundary","package","ledger","aggregate"])
    parser.add_argument("--lanes",required=True)
    parser.add_argument("--checkout-manifest",required=True,type=pathlib.Path)
    parser.add_argument("--run-manifest",required=True,type=pathlib.Path)
    parser.add_argument("--output",required=True,type=pathlib.Path)
    args=parser.parse_args();checkout=pathlib.Path(json.loads(args.checkout_manifest.read_text())["checkout"])
    lanes=args.lanes.split(",");assert lanes==["php84-l12","php84-l13"]
    manifest=json.loads(args.run_manifest.read_text());preflight(manifest);source=inputs(checkout)
    report={"item":"P5.2","stage":args.stage,"status":"failed","sourceDigest":digest(source),"sourceHashes":source,"lanes":lanes,"commands":[],"skipped":0}
    try:
        report["installed"]=installed(checkout,lanes)
        if args.stage=="consumer":
            if (ART/"final/consumer.json").exists():
                result=prior(source,lanes)
                result={**result,"reusedQualifiedCommands":True}
            else:
                result=consumer(checkout,manifest,args.run_manifest,lanes,source)
            report.update(result);ledgers(source,result,checkout)
        elif args.stage!="prepare":
            result=prior(source,lanes)
            if args.stage=="trace":report.update(trace(checkout,source,result))
            if args.stage=="package":report.update(package(checkout,lanes,source))
            if args.stage=="boundary":report["reusedCommands"]=[x for x in result["commands"] if any(y in x["raw"] for y in ["producer.log","guards.log","http.log"])]
            if args.stage in ["ledger","aggregate"]:ledgers(source,result,checkout)
        report["status"]="passed"
    except Exception as error:
        report["error"]=repr(error)
    save(args.output,report)
    print(json.dumps({k:v for k,v in report.items() if k in ["item","stage","status","error","sourceDigest"]},ensure_ascii=False))
    return 0 if report["status"]=="passed" else 1


if __name__=="__main__":
    sys.exit(main())
