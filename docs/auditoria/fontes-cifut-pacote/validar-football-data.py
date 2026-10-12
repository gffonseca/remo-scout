#!/usr/bin/env python3
"""Prova de extração local; não escreve no CIFUT nem chama API autenticada.
Uso: python3 validar-football-data.py --arquivo BRA.csv --saida prova
Sem --arquivo: baixa o CSV público brasileiro uma vez, com timeout.
"""
import argparse,csv,datetime,hashlib,io,json,collections,urllib.request
from pathlib import Path
URL='https://football-data.co.uk/new/BRA.csv'
def main():
 p=argparse.ArgumentParser();p.add_argument('--arquivo',type=Path);p.add_argument('--saida',type=Path,default=Path('prova-football-data'));a=p.parse_args()
 if a.arquivo:raw=a.arquivo.read_bytes()
 else:
  with urllib.request.urlopen(URL,timeout=30) as response:raw=response.read(10000001)
  if len(raw)>10000000:raise ValueError('Arquivo excede limite de 10 MB')
 reader=csv.DictReader(io.StringIO(raw.decode('utf-8-sig')));required={'Country','League','Season','Date','Time','Home','Away','HG','AG','Res'}
 if not required.issubset(reader.fieldnames or []):raise ValueError('Schema desconhecido; não importar')
 rows=list(reader);keys=collections.Counter();pending=[];invalid=[];normal=[]
 for line,row in enumerate(rows,2):
  try:date=datetime.datetime.strptime(row['Date'],'%d/%m/%Y').date().isoformat()
  except ValueError:invalid.append({'linha':line,'motivo':'data inválida'});continue
  key=(row['Country'],row['League'],row['Season'],date,row['Home'],row['Away']);keys[key]+=1
  values=[row[x].strip() for x in ['HG','AG','Res']]
  hg=ag=None;result=None
  if not all(values):pending.append(line);quality='resultado_ausente'
  else:
   try:
    hg=int(values[0]);ag=int(values[1]);result=values[2];expected='H' if hg>ag else 'A' if ag>hg else 'D'
    if min(hg,ag)<0 or result!=expected:raise ValueError()
    quality='resultado_consistente'
   except ValueError:invalid.append({'linha':line,'motivo':'gols/resultado inconsistentes'});quality='inconsistente'
  normal.append({'source':'football_data_co_uk','country':row['Country'],'competition':row['League'],'season':row['Season'],'match_date':date,'time_raw':row['Time'],'timezone':'unknown','home_name':row['Home'],'away_name':row['Away'],'home_goals':hg,'away_goals':ag,'result':result,'quality':quality,'source_url':URL})
 summary={'url':URL,'sha256':hashlib.sha256(raw).hexdigest(),'bytes':len(raw),'linhas':len(rows),'colunas':len(reader.fieldnames),'campos':reader.fieldnames,'temporadas':dict(collections.Counter(r['Season'] for r in rows)),'competicoes':dict(collections.Counter(r['League'] for r in rows)),'chaves_duplicadas':sum(n-1 for n in keys.values() if n>1),'resultados_ausentes_linhas':pending,'invalidos':invalid,'linhas_remo':sum(r['Home']=='Remo' or r['Away']=='Remo' for r in rows),'validado_em':datetime.datetime.now(datetime.timezone.utc).isoformat(),'limite':'Consistência interna; sem verificação externa dos placares nem autorização de uso comercial.'}
 a.saida.mkdir(parents=True,exist_ok=True);(a.saida/'verificacao.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2))
 # Hora preservada como texto: fuso não foi comprovado. Não preencher atletas/minutos.
 for name,items in [('partidas-normalizadas.csv',normal),('partidas-remo.csv',[r for r in normal if r['home_name']=='Remo' or r['away_name']=='Remo'])]:
  if not items:continue
  with (a.saida/name).open('w',encoding='utf-8-sig',newline='') as fp:
   w=csv.DictWriter(fp,fieldnames=list(items[0]),delimiter=';');w.writeheader();w.writerows(items)
 print(json.dumps(summary,ensure_ascii=False))
if __name__=='__main__':main()
