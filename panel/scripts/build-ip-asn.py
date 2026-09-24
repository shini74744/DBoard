"""Build the offline operator lookup from public-domain IPtoASN datasets.
Usage: python3 build-ip-asn.py v4.tsv.gz v6.tsv.gz output.sqlite
Sources: https://iptoasn.com/ (download data/ip2asn-v4-u32.tsv.gz and data/ip2asn-v6.tsv.gz).
IPv4/IPv6 are indexed as fixed-width network-order hex for efficient range lookup.
"""
import gzip, ipaddress, sqlite3, sys
from pathlib import Path
v4,v6,out=sys.argv[1:]
temp=Path(out+'.tmp')
temp.unlink(missing_ok=True)
db=sqlite3.connect(temp)
db.execute('CREATE TABLE ranges(version INTEGER,start TEXT,end TEXT,asn INTEGER,name TEXT,PRIMARY KEY(version,start)) WITHOUT ROWID')
for version,path in [(4,v4),(6,v6)]:
    with gzip.open(path,'rt') as f:
        batch=[]
        for line in f:
            first,last,asn,country,name=line.rstrip('\n').split('\t',4)
            if not int(asn):continue
            address=lambda x: ipaddress.ip_address(int(x) if version==4 else x).packed.hex()
            batch.append((version,address(first),address(last),int(asn),name))
            if len(batch)>=10000:db.executemany('INSERT INTO ranges VALUES(?,?,?,?,?)',batch);batch=[]
        db.executemany('INSERT INTO ranges VALUES(?,?,?,?,?)',batch)
db.commit()
print('ASN ranges',db.execute('SELECT version,count(*) FROM ranges GROUP BY version').fetchall())
db.close()
temp.replace(out)
