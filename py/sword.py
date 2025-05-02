import sys
import os
import pandas as pd
import numpy as np
import json
import io
from pathlib import Path
import logging
import contextlib
from datetime import timedelta
# ————— Disable truncation —————
pd.set_option('display.max_rows', None)      # show every row
pd.set_option('display.max_columns', None)   # show every column
pd.set_option('display.width', None)         # no wrapping to screen width
pd.set_option('display.max_colwidth', None)  # full cell contents


# —————————————————————————————
# 1) tes_new preprocessing functions
# —————————————————————————————

def process_excel_file(file_path):
    try:
        # Skip temporary files
        if os.path.basename(file_path).startswith('~$'):
            print(f"Skipping temporary file: {os.path.basename(file_path)}")
            return None

        df = pd.read_csv(file_path)
        return df
    except PermissionError:
        print(f"Permission denied for file: {os.path.basename(file_path)}")
        return None
    except Exception as e:
        print(f"Error reading {os.path.basename(file_path)}: {e}")
        return None

# —————————————————————————————
# 2) SWORDv4 pattern functions & create_full_report
#    (paste your SWORDv4.ipynb code here unchanged)
# —————————————————————————————

def pattern_directly_repeating_activity(eventlog, print_details=False, print_report=True):
    # … your full SWORDv4 function body …
    report = []
    for case in sorted(eventlog['CaseID'].unique()):
        ev = eventlog[eventlog['CaseID']==case].reset_index(drop=True)
        for i in range(1, len(ev)):
            if ev.loc[i,'Event name'] == ev.loc[i-1,'Event name']:
                td = ev.loc[i,'Timestamp'] - ev.loc[i-1,'Timestamp']
                report.append({'Case':case,'Activity':f"{ev.loc[i,'Event name']}:{ev.loc[i,'Event name']}",'Timediff':td})
    df = pd.DataFrame(report)
    if print_report:
        print(f"There were {len(df)} directly repeated events over {df['Case'].nunique()} cases.")
    return df

def pattern_activity_frequency(eventlog, delta_z=3, print_details=False, print_report=True):
    # … your full SWORDv4 function body …
    cases = sorted(eventlog['CaseID'].unique())
    if len(cases) < 30:
        print("Not enough cases available. Frequency comparison would not be meaningful.")
        return pd.DataFrame()
    acts = sorted(eventlog['Event name'].unique())
    gf = pd.DataFrame({'Activity':acts})
    gf['Mean'] = gf['Activity'].apply(
        lambda a: eventlog[eventlog['Event name']==a].groupby('CaseID').size()
                          .reindex(cases, fill_value=0).mean()
    )
    gf['SD']   = gf['Activity'].apply(
        lambda a: eventlog[eventlog['Event name']==a].groupby('CaseID').size()
                          .reindex(cases, fill_value=0).std(ddof=0)
    )
    if print_details:
        print(gf)
    recs, dev = [], 0
    for a in acts:
        m, s = float(gf.loc[gf['Activity']==a,'Mean']), float(gf.loc[gf['Activity']==a,'SD'])
        for c in cases:
            f = int(((eventlog['CaseID']==c)&(eventlog['Event name']==a)).sum())
            z = abs((f-m)/s) if s>0 else np.nan
            recs.append({'Case':c,'Activity':a,'Frequency':f,'Z':z})
            if not np.isnan(z) and z>delta_z and not (f==1 and m<1):
                dev += 1
    df = pd.DataFrame(recs)
    if print_report:
        print(f"\nThere were {dev} events with an unusual frequency.")
    return df

def pattern_resources_per_trace(eventlog, relative_to_n_activities=False, delta_z=3, print_details=False, print_report=True):
    cases = sorted(eventlog['CaseID'].unique())
    if 'Resource' not in eventlog.columns:
        print("No Resource column; skipping resources pattern.")
        return pd.DataFrame(columns=['Case','nResources','Z'])
    counts = []
    for case in cases:
        sub = eventlog[eventlog['CaseID']==case]
        nres = sub['Resource'].dropna().nunique()
        if relative_to_n_activities and len(sub)>0:
            nres /= len(sub)
        counts.append(nres)
    m, s = np.mean(counts), np.std(counts)
    recs = []; dev=0
    for case, cnt in zip(cases, counts):
        z = abs((cnt-m)/s) if s>0 else np.nan
        recs.append({'Case':case,'nResources':cnt,'Z':z})
        if not np.isnan(z) and z>delta_z:
            dev+=1
    df = pd.DataFrame(recs)
    if print_report:
        label = "per activity" if relative_to_n_activities else ""
        print(f"There were {dev} deviations from the number of resources {label} involved in a trace.")
    return df

def pattern_resource_authorization(eventlog, cutoff_rate=0.10, print_details=False, print_report=True):
    if 'Type' not in eventlog.columns:
        print("No Type column; skipping resource authorization.")
        return pd.DataFrame(columns=['Case','Event name','Type','Chance'])
    # build distribution
    dist={}
    for evt in eventlog['Event name'].unique():
        sub = eventlog[eventlog['Event name']==evt].dropna(subset=['Type'])
        total = len(sub)
        for typ, grp in sub.groupby('Type'):
            dist[(evt,typ)] = len(grp)/total if total>0 else 0
    recs=[]
    for _, row in eventlog.iterrows():
        typ = row.get('Type')
        if pd.notna(typ):
            chance = dist.get((row['Event name'], typ), 0)
            recs.append({'Case':row['CaseID'],'Event name':row['Event name'],'Type':typ,'Chance':chance})
    df = pd.DataFrame(recs)
    if print_report:
        n_unexp = df[df['Chance']<cutoff_rate].shape[0]
        n_cases = df[df['Chance']<cutoff_rate]['Case'].nunique()
        print(f"There were {n_unexp} unexpected resources over {n_cases} cases with a cutoff rate of {int(cutoff_rate*100)}%.")
    return df

def pattern_trace_duration(eventlog, delta_z=3, print_details=False, print_report=True):
    cases = sorted(eventlog['CaseID'].unique())
    recs=[]
    for case in cases:
        sub = eventlog[eventlog['CaseID']==case].dropna(subset=['Timestamp']).sort_values('Timestamp')
        if len(sub)>1:
            dur = (sub['Timestamp'].iloc[-1] - sub['Timestamp'].iloc[0]).total_seconds()/60
            recs.append({'Case':case,'Duration':dur})
    if len(recs)<30:
        print("Not enough cases available for duration test.")
        print("There should be at least 30 cases (Central Limit Theorem)")
        return pd.DataFrame(columns=['Case','Duration','Z'])
    df = pd.DataFrame(recs)
    m, s = df['Duration'].mean(), df['Duration'].std(ddof=0)
    df['Z'] = df['Duration'].apply(lambda x: abs((x-m)/s) if s>0 else np.nan)
    dev = int((df['Z']>delta_z).sum())
    if print_report:
        print(f"There were {dev} cases with an unusual duration.")
    return df

def pattern_activity_delay(eventlog, relative_to_start=True, relative_to_log=False, delta_z=3, print_details=False, print_report=True):
    starts = eventlog.dropna(subset=['Timestamp']).sort_values('Timestamp').groupby('CaseID')['Timestamp'].first().to_dict()
    diffs_by_act = {act:[] for act in eventlog['Event name'].unique()}
    for _, row in eventlog.iterrows():
        ts = row['Timestamp']; lt = row.get('Logtime', pd.NaT); act=row['Event name']
        if pd.isna(ts): continue
        if relative_to_start:
            start = starts.get(row['CaseID'])
            if pd.notna(start):
                diff = (ts-start).total_seconds()/60
            else: continue
        elif relative_to_log:
            if pd.notna(lt):
                diff = (lt-ts).total_seconds()/60
            else: continue
        else:
            diff = (ts - ts.normalize()).total_seconds()/60
        diffs_by_act[act].append(diff)
    stats = {}
    for act, arr in diffs_by_act.items():
        if len(arr)>=30:
            stats[act] = (np.mean(arr), np.std(arr, ddof=0))
    recs=[]; dev=0
    for _, row in eventlog.dropna(subset=['Timestamp']).iterrows():
        act=row['Event name']; ts=row['Timestamp']; lt=row.get('Logtime', pd.NaT)
        if act not in stats: continue
        m, s = stats[act]
        if relative_to_start:
            diff = (ts-starts[row['CaseID']]).total_seconds()/60
        elif relative_to_log:
            diff = (lt-ts).total_seconds()/60 if pd.notna(lt) else np.nan
        else:
            diff = (ts - ts.normalize()).total_seconds()/60
        z = abs((diff-m)/s) if s>0 else np.nan
        recs.append({'Case':row['CaseID'],'Event name':act,'Delay':diff,'Z':z})
        if not np.isnan(z) and z>delta_z:
            dev+=1
    df = pd.DataFrame(recs)
    if print_report:
        label = "since start of trace" if relative_to_start else "between event and log" if relative_to_log else "since midnight"
        print(f"There were {dev} events at an unusual time ({label}).")
    return df

def pattern_time_between_activities(eventlog, delta_z=3, print_details=False, print_report=True):
    recs=[]
    for case, sub in eventlog.dropna(subset=['Timestamp']).groupby('CaseID'):
        ts = sub.sort_values('Timestamp')['Timestamp']
        for t0, t1 in zip(ts, ts.shift(-1).dropna()):
            recs.append({'Case':case,'Duration':(t1-t0).total_seconds()/60})
    df = pd.DataFrame(recs)
    if df.empty:
        print("No inter-activity durations available.")
        return df
    m, s = df['Duration'].mean(), df['Duration'].std(ddof=0)
    df['Z'] = df['Duration'].apply(lambda x: abs((x-m)/s) if s>0 else np.nan)
    dev = int((df['Z']>delta_z).sum())
    ncase = int(df[df['Z']>delta_z]['Case'].nunique())
    if print_report:
        print(f"There were {dev} events with unusual durations over {ncase} cases.")
    return df

# … include all other pattern_... functions from SWORDv4.ipynb here …
# …

def create_full_report(eventlog, delta_z=3, print_details=False, print_report=True):
    # … your full createFullReport body …
    cases = sorted(eventlog['CaseID'].unique())
    rpt = pd.DataFrame({'CaseID':cases})
    if print_details: print("\nPattern   -Frequent occurrence of activity-")
    freq = pattern_activity_frequency(eventlog, delta_z, print_details, print_report)
    rpt['z_Frequent'] = rpt['CaseID'].apply(
        lambda c: freq[freq['Case']==c]['Z'].max() if not freq.empty else np.nan
    )
    if print_details: print("\nPattern   -Occurrence of directly repeating activity-")
    rep = pattern_directly_repeating_activity(eventlog, print_details, print_report)
    rpt['n_Repeats'] = rpt['CaseID'].apply(lambda c: rep[rep['Case']==c].shape[0])
    # … and so on for the rest of your patterns …
    rpt['z_Max'] = rpt.loc[:, rpt.columns.difference(['CaseID'])].max(axis=1, skipna=True)
    return rpt

if __name__ == "__main__":
    try:
        if len(sys.argv) < 2:
                print("Usage: python sword.py <path_to_csv_file>")
                sys.exit(1)

        input_path = sys.argv[1]
        if not os.path.exists(input_path):
            print(f"File not found: {input_path}")
            sys.exit(1)

        base_name = Path(input_path).stem
        output_dir = os.path.join(os.path.dirname(input_path), "output")
        os.makedirs(output_dir, exist_ok=True)

        logging.info(f"Processing file: {input_path}")
        df = pd.read_csv(input_path)
        logging.info(f"CSV loaded with {len(df)} rows")

        # Data cleaning
        df['CaseID'] = df.apply(
            lambda r: r['Affected user'] if r['User full name'] == "-" else r['User full name'],
            axis=1
        )
        df.drop(['User full name', 'Affected user'], axis=1, errors='ignore', inplace=True)

        df['Event name'] = df.apply(
            lambda r: f"{r['Event name']} {r['Description'].split('id ')[-1].split(')')[0]}"
            if pd.notna(r['Description']) and 'id ' in r['Description'] else r['Event name'],
            axis=1
        )
        df['Event name'] = df.apply(lambda r: f"{r['Component']}: {r['Event name']}", axis=1)

        try:
            df['Time'] = pd.to_datetime(df['Time'], format='%d/%m/%y, %H:%M')
        except:
            try:
                df['Time'] = pd.to_datetime(df['Time'], format='%d/%m/%y %H:%M')
            except:
                df['Time'] = pd.to_datetime(df['Time'], dayfirst=True, errors='coerce')

        df = df.dropna(subset=['Time']).sort_values('Time')
        df.rename(columns={'Time': 'Timestamp'}, inplace=True)

        pos = df.columns.get_loc('CaseID') + 1
        df.insert(pos, 'Resource', '')
        df.insert(pos + 1, 'Logtime', '')

        # Fixing timestamp overlaps
        td, pm, pn = 0, None, None
        for i in range(1, len(df)):
            ci = df.iloc[i]
            mn = ci['Timestamp'].minute
            nm = ci['CaseID']
            if nm == pn and mn == pm:
                td += 1
                df.iat[i, df.columns.get_loc('Timestamp')] = df.iloc[i - 1]['Timestamp'] + timedelta(seconds=td)
            else:
                td = 0
            pn, pm = nm, mn

        # Run report
        logging.info("Running full SWORD report")
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            print("#--Full SWORD Report--")
            report = create_full_report(df)
            print(report)

        lines = buf.getvalue().splitlines()
        json_out = os.path.join(output_dir, f"{base_name}_sword_output.json")
        with open(json_out, 'w', encoding='utf-8') as f:
            json.dump({"console_output": lines}, f, ensure_ascii=False, indent=4)

        logging.info(f"✅ Report written to {json_out}")
        logging.info("✅ Python script finished successfully")

    except Exception as e:
        logging.exception("❌ Error occurred during script execution")
        print("An error occurred. Check log file for details.")