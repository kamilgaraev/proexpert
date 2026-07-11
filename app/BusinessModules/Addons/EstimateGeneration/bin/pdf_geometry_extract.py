#!/usr/bin/env python3
import argparse
import hashlib
import json
import os
import sys

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

def failure(code):
    sys.stderr.write(json.dumps({"code":code,"safe_message":"Не удалось безопасно обработать документ.","retryable":False},ensure_ascii=False))
    raise SystemExit(2)

def extract(path, max_pages, max_objects):
    try:
        import pypdfium2 as pdfium
        from pypdfium2 import raw
        from pypdfium2.version import PYPDFIUM_INFO
    except Exception: failure("pypdfium2_unavailable")
    try: document=pdfium.PdfDocument(path)
    except Exception: failure("pdf_invalid")
    pages=[]; entities=[]; texts=[]; warnings=[]
    for page_index in range(min(len(document),max_pages)):
        page=document[page_index]; width,height=page.get_size(); rotation=page.get_rotation()
        page_entities=0; page_images=0
        text_page=page.get_textpage()
        for object_index,obj in enumerate(page.get_objects(max_depth=8,textpage=text_page)):
            if object_index>=max_objects: failure("pdf_object_limit_exceeded")
            bounds=[float(v) for v in obj.get_bounds()]
            identity=f"page:{page_index+1}:object:{object_index}"
            matrix=[float(v) for v in obj.get_matrix().get()]
            if obj.type==raw.FPDF_PAGEOBJ_PATH:
                entities.append({"handle":identity,"type":"path","layer":"page","points":[bounds[:2],bounds[2:]],"source_lineage":[identity],"transform":matrix,"layout":f"page:{page_index+1}"}); page_entities+=1
            elif obj.type==raw.FPDF_PAGEOBJ_TEXT:
                value=obj.extract()[:4096]
                texts.append({"handle":identity,"type":"text","layer":"page","text":value,"position":bounds[:2],"layout":f"page:{page_index+1}","source_operator":identity})
            elif obj.type==raw.FPDF_PAGEOBJ_IMAGE: page_images+=1
        classification="vector" if page_entities else ("mixed" if page_images and texts else "raster")
        pages.append({"page_number":page_index+1,"width":float(width),"height":float(height),"rotation":int(rotation),"page_box":[0.0,0.0,float(width),float(height)],"transform":[1.0,0.0,0.0,1.0,0.0,0.0],"classification":classification})
    if not entities: failure("pdf_vector_geometry_missing")
    bounds=[]
    points=[p for entity in entities for p in entity["points"]]
    if points: bounds=[min(p[0] for p in points),min(p[1] for p in points),max(p[0] for p in points),max(p[1] for p in points)]
    fingerprint="sha256:"+hashlib.sha256(open(path,"rb").read()).hexdigest()
    return {"schema_version":1,"runtime_version":f"pdf-geometry:v1;pypdfium2:{PYPDFIUM_INFO}","source_fingerprint":fingerprint,"source_unit":None,"unit_status":"unknown","bounds":bounds,"layers":[{"name":"page","visible":True}],"blocks":[],"entities":entities,"texts":texts,"dimensions":[],"pages":pages,"scale_candidates":[],"warnings":warnings}

def legacy(contract):
    pages=[]
    for page in contract["pages"]:
        page_no=page["page_number"]
        vectors=[]
        for entity in contract["entities"]:
            if entity["layout"]==f"page:{page_no}": vectors.append({"kind":"path","bbox":None,"geometry":{"points":entity["points"]},"style":{"source_operator":entity["handle"]}})
        page_text=[t for t in contract["texts"] if t["layout"]==f"page:{page_no}"]
        pages.append({"page_number":page_no,"width":page["width"],"height":page["height"],"rotation":page["rotation"],"text_blocks":[{"text":t["text"],"bbox":None,"block_no":i,"block_type":0} for i,t in enumerate(page_text)],"vector_elements":vectors,"visual_metrics":{"path_count":len(vectors),"line_count":0,"curve_count":0,"rect_count":0,"vector_element_count":len(vectors),"stored_vector_element_count":len(vectors),"text_block_count":len(page_text),"table_candidate_count":0,"contour_candidate_count":0,"title_block_candidate_count":0,"geometry_density":0},"page_role":"geometry_only" if vectors else "empty","signals":["vector_geometry"] if vectors else ["text_empty"],"preview":{"path":None}})
    return {"provider":"pypdfium2","model":"geometry_v1","pages":pages,"metadata":{"page_count":len(pages),"processed_page_count":len(pages),"pypdfium2_version":"5.8.0"}}

def main():
    p=argparse.ArgumentParser(); p.add_argument("--input",required=True); p.add_argument("--workspace",default=""); p.add_argument("--contract-vector",action="store_true"); p.add_argument("--filename",default=""); p.add_argument("--preview-dir",default=""); p.add_argument("--max-pages",type=int,default=200); p.add_argument("--max-vector-elements",type=int,default=5000); p.add_argument("--render-preview",action="store_true"); a=p.parse_args()
    real=os.path.realpath(a.input)
    if a.workspace and os.path.commonpath([real,os.path.realpath(a.workspace)])!=os.path.realpath(a.workspace): failure("pdf_path_invalid")
    result=extract(real,a.max_pages,a.max_vector_elements)
    sys.stdout.write(json.dumps(result if a.contract_vector else legacy(result),separators=(",",":"),ensure_ascii=False,allow_nan=False))

if __name__=="__main__":
    try: main()
    except SystemExit: raise
    except Exception: failure("pdf_parse_failed")
