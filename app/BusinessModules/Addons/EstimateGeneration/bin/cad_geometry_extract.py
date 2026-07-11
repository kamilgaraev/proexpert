#!/usr/bin/env python3
import argparse
import hashlib
import json
import math
import os
import subprocess
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

SCHEMA_VERSION = 1
SUPPORTED = {"LINE", "ARC", "CIRCLE", "LWPOLYLINE", "POLYLINE", "INSERT", "TEXT", "MTEXT", "DIMENSION"}

def fail(code, retryable=False):
    sys.stderr.write(json.dumps({"code": code, "safe_message": "Не удалось безопасно обработать чертёж.", "retryable": retryable}, ensure_ascii=False))
    raise SystemExit(2)

def handle(value):
    if isinstance(value, list) and value:
        return format(int(value[-1]), "X")
    return str(value or "0")

def unit(code):
    units = {1:"in", 2:"ft", 4:"mm", 5:"cm", 6:"m"}
    value = units.get(int(code or 0))
    return value, "confirmed" if value else "unknown"

def point(value):
    return [float(value[0]), float(value[1])]

def bounds_of(entities):
    pts=[]
    for entity in entities:
        pts += entity.get("points", [])
        if "center" in entity: pts.append(entity["center"])
    return [min(p[0] for p in pts), min(p[1] for p in pts), max(p[0] for p in pts), max(p[1] for p in pts)] if pts else []

def parse_dxf(path):
    import ezdxf
    doc=ezdxf.readfile(path)
    source_unit,status=unit(doc.header.get("$INSUNITS", 0))
    layers=[{"name": layer.dxf.name, "visible": not layer.is_off()} for layer in doc.layers]
    entities=[]; texts=[]; dimensions=[]; warnings=[]; unsupported={}
    for layout in doc.layouts:
        for e in layout:
            typ=e.dxftype(); h=str(e.dxf.handle or f"generated-{len(entities)}"); layer=str(e.dxf.layer); base={"handle":h,"type":typ.lower(),"layer":layer,"layout":layout.name}
            if typ=="LINE": base["points"]=[point(e.dxf.start),point(e.dxf.end)]; entities.append(base)
            elif typ in ("LWPOLYLINE","POLYLINE"):
                base["points"]=[point(v) for v in (e.get_points("xy") if typ=="LWPOLYLINE" else [v.dxf.location for v in e.vertices])]; base["closed"]=bool(e.closed); entities.append(base)
            elif typ in ("ARC","CIRCLE"):
                base.update(center=point(e.dxf.center),radius=float(e.dxf.radius));
                if typ=="ARC": base.update(start_angle=float(e.dxf.start_angle),end_angle=float(e.dxf.end_angle))
                entities.append(base)
            elif typ in ("TEXT","MTEXT"):
                texts.append({"handle":h,"type":typ.lower(),"layer":layer,"text":str(e.dxf.text if typ=="TEXT" else e.text),"position":point(e.dxf.insert),"layout":layout.name})
            elif typ=="DIMENSION": dimensions.append({"handle":h,"type":"dimension","layer":layer,"text":str(e.dxf.text),"layout":layout.name})
            elif typ=="INSERT":
                matrix=list(e.matrix44()); base.update(block=str(e.dxf.name),transform=[[float(x) for x in row] for row in matrix],source_lineage=[h]); entities.append(base)
            else: unsupported[typ]=unsupported.get(typ,0)+1
    if unsupported: warnings.append({"code":"unsupported_entities","counts":unsupported,"blocking":True})
    blocks=[{"name": block.name,"entity_count":len(block)} for block in doc.blocks if not block.name.startswith("*")]
    return source_unit,status,layers,blocks,entities,texts,dimensions,warnings,"ezdxf:1.4.4"

def parse_dwg(path, dwgread, workspace):
    try:
        proc=subprocess.run([dwgread,"-O","JSON",path],cwd=workspace,capture_output=True,text=True,timeout=30,check=False)
    except (OSError,subprocess.TimeoutExpired): fail("libredwg_unavailable", True)
    if proc.returncode != 0: fail("dwg_decode_failed")
    try: data=json.loads(proc.stdout)
    except Exception: fail("dwg_contract_invalid")
    entities=[]; texts=[]; dimensions=[]; layers=[]; warnings=[]; unsupported={}
    for obj in data.get("OBJECTS",[]):
        typ=obj.get("entity")
        if obj.get("object")=="LAYER": layers.append({"name":str(obj.get("name","0")),"visible":not bool(obj.get("flag",0)&1)})
        if not typ: continue
        h=handle(obj.get("handle")); base={"handle":h,"type":typ.lower(),"layer":handle(obj.get("layer")),"layout":"model"}
        if typ=="LINE": base["points"]=[point(obj["start"]),point(obj["end"])]; entities.append(base)
        elif typ=="LWPOLYLINE": base["points"]=[point(p) for p in obj.get("points",[])]; base["closed"]=bool(obj.get("flag",0)&512); entities.append(base)
        elif typ in ("ARC","CIRCLE"): base.update(center=point(obj["center"]),radius=float(obj["radius"])); entities.append(base)
        elif typ in ("TEXT","MTEXT"): texts.append({"handle":h,"type":typ.lower(),"layer":base["layer"],"text":str(obj.get("text_value","")),"position":point(obj.get("ins_pt",[0,0])),"layout":"model"})
        elif typ=="DIMENSION": dimensions.append({"handle":h,"type":"dimension","layer":base["layer"],"text":str(obj.get("text","")),"layout":"model"})
        elif typ in SUPPORTED: entities.append(base)
        else: unsupported[typ]=unsupported.get(typ,0)+1
    if unsupported: warnings.append({"code":"unsupported_entities","counts":unsupported,"blocking":True})
    measurement=data.get("Template",{}).get("MEASUREMENT",0); source_unit,status=("mm","confirmed") if measurement==1 else (None,"unknown")
    if not entities and not texts and not dimensions: fail("dwg_geometry_empty")
    return source_unit,status,layers,[],entities,texts,dimensions,warnings,"libredwg:0.13.4"

def main():
    p=argparse.ArgumentParser(); p.add_argument("--input",required=True); p.add_argument("--workspace",required=True); p.add_argument("--dwgread",default="dwgread"); p.add_argument("--max-output-bytes",type=int,default=16777216); a=p.parse_args()
    source=os.path.realpath(a.input); workspace=os.path.realpath(a.workspace)
    if os.path.commonpath([source,workspace]) != workspace or not os.path.isfile(source): fail("cad_path_invalid")
    digest="sha256:"+hashlib.sha256(open(source,"rb").read()).hexdigest()
    parsed=parse_dwg(source,a.dwgread,workspace) if source.lower().endswith(".dwg") else parse_dxf(source)
    source_unit,status,layers,blocks,entities,texts,dimensions,warnings,runtime=parsed
    result={"schema_version":SCHEMA_VERSION,"runtime_version":"cad-geometry:v1;"+runtime,"source_fingerprint":digest,"source_unit":source_unit,"unit_status":status,"bounds":bounds_of(entities),"layers":layers,"blocks":blocks,"entities":entities,"texts":texts,"dimensions":dimensions,"pages":[],"scale_candidates":[],"warnings":warnings}
    output=json.dumps(result,separators=(",",":"),ensure_ascii=False,allow_nan=False)
    if len(output.encode())>a.max_output_bytes: fail("cad_output_oversize")
    sys.stdout.write(output)

if __name__=="__main__":
    try: main()
    except SystemExit: raise
    except Exception: fail("cad_parse_failed")
