import sys
import os
import pandas as pd
import numpy as np
import json
import io
import contextlib
import logging
from datetime import timedelta
from pandas import NaT
from datetime import timedelta
from pathlib import Path

# ————— pandas display settings —————
pd.set_option('display.max_rows', None)
pd.set_option('display.max_columns', None)
pd.set_option('display.width', None)
pd.set_option('display.max_colwidth', None)

def format_duration(minutes: float) -> str:
    secs = int(round(minutes * 60))
    days, rem = divmod(secs, 86400)
    hrs, rem = divmod(rem, 3600)
    mins, secs = divmod(rem, 60)
    parts = []
    if days:
        parts.append(f"{days} Days")
    parts.append(f"{hrs:02d}:{mins:02d}:{secs:02d}")
    return ", ".join(parts)
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

def clean_data(df):

    drop_comps = [
        "Overview report", "Submission comments", "Grader report", "Logs",
        "User report", "Zoom meeting", "Forum", "Level Up XP", "System"
    ]
    df = df[~df["Component"].isin(drop_comps)].copy()
    return df

# —————————————————————————————
# 2) Main Execution Block (converted for CLI & JSON output)
# —————————————————————————————

def pattern_directly_repeating_activity(eventlog, delta_z=3, print_details=True, print_report=True):
    total_dev = 0
    for case in sorted(eventlog['CaseID'].unique()):
        ev = eventlog[eventlog['CaseID']==case].reset_index(drop=True)
        for i in range(1, len(ev)):
            if ev.loc[i,'Event name'] == ev.loc[i-1,'Event name']:
                total_dev += 1
    if print_report:
        print(f"There were {total_dev} directly repeated events over {eventlog['CaseID'].nunique()} cases.")

def pattern_activity_frequency(eventlog, delta_z=3, print_details=True, print_report=True):
    cases = sorted(eventlog['CaseID'].unique())
    acts = sorted(eventlog['Event name'].unique())
    total_dev = 0
    for act in acts:
        freqs = eventlog[eventlog['Event name']==act] \
                  .groupby('CaseID').size() \
                  .reindex(cases, fill_value=0)
        m, s = freqs.mean(), freqs.std(ddof=1)
        if print_details:
            print(f"Activity {act}: Mean={m:.6f}, SD={s:.6f}")
        for case in cases:
            f = int(freqs.loc[case])
            z = abs((f-m)/s) if s>0 else np.nan
            if z>delta_z and not (f==1 and m<1):
                print(f"Activity \"{act}\" occured {f} times in {case} (Mean={m:.6f}, SD={s:.6f}, Z={z:.3f})")
                total_dev += 1
    if print_report:
        print(f"\nThere were {total_dev} events with an unusual frequency.")

def pattern_resources_per_trace(eventlog, relative_to_n_activities=False,
                                delta_z=3, print_details=True, print_report=True):
    cases = sorted(eventlog['CaseID'].unique())
    counts = []
    for case in cases:
        sub = eventlog[eventlog['CaseID']==case]
        n = sub['Resource'].nunique()
        if relative_to_n_activities and len(sub)>0:
            n = n/len(sub)
        counts.append(n)
    m, s = np.mean(counts), np.std(counts, ddof=1)
    if print_details:
        print(f"Mean resources = {m:.6f}, SD = {s:.6f}")
    total_dev = 0
    for case, n in zip(cases, counts):
        z = abs((n-m)/s) if s>0 else np.nan
        if z>delta_z:
            print(f"Case {case}: nResources={n:.6f} (Z={z:.3f})")
            total_dev += 1
    if print_report:
        print(f"There were {total_dev} deviations from the number of resources"
              f"{' per activity' if relative_to_n_activities else ''}.")

def pattern_resource_authorization(eventlog, cutoff_rate=0.10,
                                   print_details=True, print_report=True):
    if 'Type' not in eventlog.columns:
        print("No resource type available. Skipping pattern.")
        return
    total_dev = 0
    for _, row in eventlog.dropna(subset=['Type']).iterrows():
        sub = eventlog[eventlog['Event name']==row['Event name']].dropna(subset=['Type'])
        p = (sub['Type']==row['Type']).sum() / len(sub)
        if p < cutoff_rate:
            print(f"Case {row['CaseID']}: resource type {row['Type']} for {row['Event name']} has p={p:.6f}")
            total_dev += 1
    if print_report:
        print(f"There were {total_dev} unexpected resources with cutoff rate {cutoff_rate*100:.0f}%.")

def pattern_trace_duration(eventlog, delta_z=3, print_details=True, print_report=True):
    rec = []
    for case in sorted(eventlog['CaseID'].unique()):
        sub = eventlog[eventlog['CaseID']==case] \
              .dropna(subset=['Timestamp']) \
              .sort_values('Timestamp')
        if len(sub)>1:
            dur = (sub['Timestamp'].iloc[-1] - sub['Timestamp'].iloc[0]).total_seconds()/60
            rec.append(dur)
    if not rec:
        if print_report:
            print("There were 0 cases with an unusual duration.")
        return
    m, s = np.mean(rec), np.std(rec, ddof=1)
    total_dev = sum(1 for d in rec if s>0 and abs((d-m)/s)>delta_z)
    if print_report:
        print(f"There were {total_dev} cases with an unusual duration.")

def pattern_activity_delay(eventlog, relative_to_start=True, relative_to_log=False,
                           delta_z=3, print_details=True, print_report=True):
    # 1) Build stats per activity
    stats = {}
    starts = (
        eventlog.dropna(subset=['Timestamp'])
                .sort_values('Timestamp')
                .groupby('CaseID')['Timestamp']
                .first()
    )
    for act in eventlog['Event name'].unique():
        diffs = []
        sub = eventlog[eventlog['Event name']==act]
        for _, row in sub.iterrows():
            ts = row['Timestamp']
            if pd.isna(ts):
                continue
            if relative_to_start:
                diffs.append((ts - starts[row['CaseID']]).total_seconds()/60)
            elif relative_to_log and pd.notna(row['Logtime']):
                diffs.append((row['Logtime'] - ts).total_seconds()/60)
            else:
                diffs.append((ts - pd.Timestamp(ts.date())).total_seconds()/60)
        if diffs:
            stats[act] = (float(np.mean(diffs)), float(np.std(diffs, ddof=1)))

    # 2) Detect anomalies
    total_dev = 0
    for _, row in eventlog.iterrows():
        act = row['Event name']
        ts  = row['Timestamp']
        if act not in stats or pd.isna(ts):
            continue
        mu, sd = stats[act]
        if sd <= 0:
            continue
        if relative_to_start:
            diff = (ts - starts[row['CaseID']]).total_seconds()/60
            label = "since start of trace"
        elif relative_to_log:
            diff = ((row['Logtime'] - ts).total_seconds()/60) if pd.notna(row['Logtime']) else 0
            label = "logging delay"
        else:
            diff = (ts - pd.Timestamp(ts.date())).total_seconds()/60
            label = "time of day"
        z = abs((diff - mu)/sd)
        if z > delta_z:
            if print_details:
                print(f"---Detected unusual frequencies in {row['CaseID']}---")
                print(f"Unusual {label} for activity |{act}|")
                print(f"It occurred {format_duration(diff)}; mean {format_duration(mu)}, SD {format_duration(sd)}")
            total_dev += 1

    # 3) Summary
    if print_report:
        postfix = "" if relative_to_start else " (logging)"
        print(f"There were {total_dev} events at an unusual time{postfix}.")

def pattern_time_between_activities(eventlog, delta_z=3, print_details=True, print_report=True):
    rec = []
    for case, sub in eventlog.dropna(subset=['Timestamp']).groupby('CaseID'):
        ts = sub.sort_values('Timestamp')['Timestamp']
        evs = sub.sort_values('Timestamp')['Event name'].tolist()
        for i in range(len(ts)-1):
            dur = (ts.iloc[i+1] - ts.iloc[i]).total_seconds()/60
            if dur > delta_z and print_details:
                print(f"In case {case}, there was a duration of {format_duration(dur)} between |{evs[i]}| and |{evs[i+1]}|.")
            if dur > delta_z:
                rec.append(dur)
    total_dev = len(rec)
    if print_report:
        print(f"There were {total_dev} events with unusual durations.")

# ── 3) Single‐file pipeline ──

if __name__ == "__main__":



    INPUT_FILE = sys.argv[1]
    if not os.path.exists(INPUT_FILE ):
        print(f"File not found: {INPUT_FILE}")
        sys.exit(1)
        
    base_name = Path(INPUT_FILE).stem
    OUTPUT_DIR = os.path.join(os.path.dirname(INPUT_FILE), "output")
    os.makedirs(OUTPUT_DIR, exist_ok=True)

    print(f"\n=== Processing {INPUT_FILE} ===")
    logging.info(f"Processing file: {INPUT_FILE}")
    df = pd.read_csv(INPUT_FILE)
    logging.info(f"CSV loaded with {len(df)} rows")
    if df is None: exit(1)
    df = clean_data(df)
    if df is None: exit(1)

    # Build CaseID & drop originals
    df['CaseID'] = df.apply(
        lambda r: r['Affected user'] if r['User full name']=="-" else r['User full name'],
        axis=1
    )
    df_final = df.drop(['User full name','Affected user'], axis=1, errors='ignore')

    # Process Event name with ID and Component
    df_final['Event name'] = df_final.apply(
        lambda r: f"{r['Event name']} {r['Description'].split('id ')[-1].split(')')[0]}"
                  if pd.notna(r['Description']) and 'id ' in r['Description'] else r['Event name'],
        axis=1
    )
    df_final['Event name'] = df_final.apply(
        lambda r: f"{r['Component']}: {r['Event name']}", axis=1
    )

    # Parse Timestamp
    tc = df_final['Time']
    try:
        df_final['Timestamp'] = pd.to_datetime(tc, format='%d/%m/%y, %H:%M', dayfirst=True, errors='raise')
    except ValueError:
        try:
            df_final['Timestamp'] = pd.to_datetime(tc, format='%d/%m/%y %H:%M', dayfirst=True, errors='raise')
        except ValueError:
            df_final['Timestamp'] = pd.to_datetime(tc, dayfirst=True, errors='coerce')
    df_final.dropna(subset=['Timestamp'], inplace=True)
    df_final.sort_values('Timestamp', inplace=True)

    # Insert blank Resource & Logtime
    pos = df_final.columns.get_loc('CaseID') + 1
    df_final.insert(pos,   'Resource', '')
    df_final.insert(pos+1, 'Logtime',  NaT)

    # Adjust identical‐minute events
    td, prev_name, prev_min = 0, None, None
    for i in range(1, len(df_final)):
        nm = df_final.iloc[i]['CaseID']
        mn = df_final.iloc[i]['Timestamp'].minute
        if nm == prev_name and mn == prev_min:
            td += 1
            df_final.iat[i, df_final.columns.get_loc('Timestamp')] = (
                df_final.iloc[i-1]['Timestamp'] + timedelta(seconds=td)
            )
        else:
            td = 0
        prev_name, prev_min = nm, mn

    # Capture summaries/detections per pattern
    patterns = [
        ("Frequent occurrence of activity", 
             lambda: pattern_activity_frequency(df_final, 3, True, True)),
        ("Occurrence of directly repeating activity",
             lambda: pattern_directly_repeating_activity(df_final, 3, True, True)),
        ("Activity executed by unauthorized resource",
             lambda: pattern_resource_authorization(df_final, 0.10, True, True)),
        ("Activities executed by number of resources",
             lambda: pattern_resources_per_trace(df_final, False, 3, True, True)),
        ("Activities executed by number of resources (per event)",
             lambda: pattern_resources_per_trace(df_final, True, 3, True, True)),
        ("Occurrence of activity outside of time period",
             lambda: pattern_activity_delay(df_final, False, False, 3, True, True)),
        ("Delay between start of trace and activity is out of bounds",
             lambda: pattern_activity_delay(df_final, True, False, 3, True, True)),
        ("Delay between event and logging out of bounds",
             lambda: pattern_activity_delay(df_final, False, True, 3, True, True)),
        ("Time between activities out of bounds",
             lambda: pattern_time_between_activities(df_final, 3, True, True)),
        ("Duration of trace out of bounds",
             lambda: pattern_trace_duration(df_final, 3, True, True)),
    ]

    sword_results = {}
    for title, runner in patterns:
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            print(f"\nPattern   -{title}-\n")
            runner()
        sword_results[title] = buf.getvalue().splitlines()

    base = os.path.splitext(os.path.basename(INPUT_FILE))[0]
    json_path = os.path.join(OUTPUT_DIR, f"{base}_sword_output.json")
    with open(json_path, 'w', encoding='utf-8') as jf:
        json.dump(sword_results, jf, ensure_ascii=False, indent=2)

    print(f"SWORD patterns JSON saved to {json_path}")
    print("\nAll files processed!")



# import sys
# import os
# import pandas as pd
# import numpy as np
# import json
# import io
# from pathlib import Path
# import logging
# import contextlib
# from datetime import timedelta
# # ————— Disable truncation —————
# pd.set_option('display.max_rows', None)      # show every row
# pd.set_option('display.max_columns', None)   # show every column
# pd.set_option('display.width', None)         # no wrapping to screen width
# pd.set_option('display.max_colwidth', None)  # full cell contents


# # —————————————————————————————
# # 1) tes_new preprocessing functions
# # —————————————————————————————

# def process_excel_file(file_path):
#     try:
#         # Skip temporary files
#         if os.path.basename(file_path).startswith('~$'):
#             print(f"Skipping temporary file: {os.path.basename(file_path)}")
#             return None

#         df = pd.read_csv(file_path)
#         return df
#     except PermissionError:
#         print(f"Permission denied for file: {os.path.basename(file_path)}")
#         return None
#     except Exception as e:
#         print(f"Error reading {os.path.basename(file_path)}: {e}")
#         return None

# # —————————————————————————————
# # 2) SWORDv4 pattern functions & create_full_report
# #    (paste your SWORDv4.ipynb code here unchanged)
# # —————————————————————————————

# def pattern_directly_repeating_activity(eventlog, print_details=False, print_report=True):
#     # … your full SWORDv4 function body …
#     report = []
#     for case in sorted(eventlog['CaseID'].unique()):
#         ev = eventlog[eventlog['CaseID']==case].reset_index(drop=True)
#         for i in range(1, len(ev)):
#             if ev.loc[i,'Event name'] == ev.loc[i-1,'Event name']:
#                 td = ev.loc[i,'Timestamp'] - ev.loc[i-1,'Timestamp']
#                 report.append({'Case':case,'Activity':f"{ev.loc[i,'Event name']}:{ev.loc[i,'Event name']}",'Timediff':td})
#     df = pd.DataFrame(report)
#     if print_report:
#         print(f"There were {len(df)} directly repeated events over {df['Case'].nunique()} cases.")
#     return df

# def pattern_activity_frequency(eventlog, delta_z=3, print_details=False, print_report=True):
#     # … your full SWORDv4 function body …
#     cases = sorted(eventlog['CaseID'].unique())
#     if len(cases) < 30:
#         print("Not enough cases available. Frequency comparison would not be meaningful.")
#         return pd.DataFrame()
#     acts = sorted(eventlog['Event name'].unique())
#     gf = pd.DataFrame({'Activity':acts})
#     gf['Mean'] = gf['Activity'].apply(
#         lambda a: eventlog[eventlog['Event name']==a].groupby('CaseID').size()
#                           .reindex(cases, fill_value=0).mean()
#     )
#     gf['SD']   = gf['Activity'].apply(
#         lambda a: eventlog[eventlog['Event name']==a].groupby('CaseID').size()
#                           .reindex(cases, fill_value=0).std(ddof=0)
#     )
#     if print_details:
#         print(gf)
#     recs, dev = [], 0
#     for a in acts:
#         m, s = float(gf.loc[gf['Activity']==a,'Mean']), float(gf.loc[gf['Activity']==a,'SD'])
#         for c in cases:
#             f = int(((eventlog['CaseID']==c)&(eventlog['Event name']==a)).sum())
#             z = abs((f-m)/s) if s>0 else np.nan
#             recs.append({'Case':c,'Activity':a,'Frequency':f,'Z':z})
#             if not np.isnan(z) and z>delta_z and not (f==1 and m<1):
#                 dev += 1
#     df = pd.DataFrame(recs)
#     if print_report:
#         print(f"\nThere were {dev} events with an unusual frequency.")
#     return df

# def pattern_resources_per_trace(eventlog, relative_to_n_activities=False, delta_z=3, print_details=False, print_report=True):
#     cases = sorted(eventlog['CaseID'].unique())
#     if 'Resource' not in eventlog.columns:
#         print("No Resource column; skipping resources pattern.")
#         return pd.DataFrame(columns=['Case','nResources','Z'])
#     counts = []
#     for case in cases:
#         sub = eventlog[eventlog['CaseID']==case]
#         nres = sub['Resource'].dropna().nunique()
#         if relative_to_n_activities and len(sub)>0:
#             nres /= len(sub)
#         counts.append(nres)
#     m, s = np.mean(counts), np.std(counts)
#     recs = []; dev=0
#     for case, cnt in zip(cases, counts):
#         z = abs((cnt-m)/s) if s>0 else np.nan
#         recs.append({'Case':case,'nResources':cnt,'Z':z})
#         if not np.isnan(z) and z>delta_z:
#             dev+=1
#     df = pd.DataFrame(recs)
#     if print_report:
#         label = "per activity" if relative_to_n_activities else ""
#         print(f"There were {dev} deviations from the number of resources {label} involved in a trace.")
#     return df

# def pattern_resource_authorization(eventlog, cutoff_rate=0.10, print_details=False, print_report=True):
#     if 'Type' not in eventlog.columns:
#         print("No Type column; skipping resource authorization.")
#         return pd.DataFrame(columns=['Case','Event name','Type','Chance'])
#     # build distribution
#     dist={}
#     for evt in eventlog['Event name'].unique():
#         sub = eventlog[eventlog['Event name']==evt].dropna(subset=['Type'])
#         total = len(sub)
#         for typ, grp in sub.groupby('Type'):
#             dist[(evt,typ)] = len(grp)/total if total>0 else 0
#     recs=[]
#     for _, row in eventlog.iterrows():
#         typ = row.get('Type')
#         if pd.notna(typ):
#             chance = dist.get((row['Event name'], typ), 0)
#             recs.append({'Case':row['CaseID'],'Event name':row['Event name'],'Type':typ,'Chance':chance})
#     df = pd.DataFrame(recs)
#     if print_report:
#         n_unexp = df[df['Chance']<cutoff_rate].shape[0]
#         n_cases = df[df['Chance']<cutoff_rate]['Case'].nunique()
#         print(f"There were {n_unexp} unexpected resources over {n_cases} cases with a cutoff rate of {int(cutoff_rate*100)}%.")
#     return df

# def pattern_trace_duration(eventlog, delta_z=3, print_details=False, print_report=True):
#     cases = sorted(eventlog['CaseID'].unique())
#     recs=[]
#     for case in cases:
#         sub = eventlog[eventlog['CaseID']==case].dropna(subset=['Timestamp']).sort_values('Timestamp')
#         if len(sub)>1:
#             dur = (sub['Timestamp'].iloc[-1] - sub['Timestamp'].iloc[0]).total_seconds()/60
#             recs.append({'Case':case,'Duration':dur})
#     if len(recs)<30:
#         print("Not enough cases available for duration test.")
#         print("There should be at least 30 cases (Central Limit Theorem)")
#         return pd.DataFrame(columns=['Case','Duration','Z'])
#     df = pd.DataFrame(recs)
#     m, s = df['Duration'].mean(), df['Duration'].std(ddof=0)
#     df['Z'] = df['Duration'].apply(lambda x: abs((x-m)/s) if s>0 else np.nan)
#     dev = int((df['Z']>delta_z).sum())
#     if print_report:
#         print(f"There were {dev} cases with an unusual duration.")
#     return df

# def pattern_activity_delay(eventlog, relative_to_start=True, relative_to_log=False, delta_z=3, print_details=False, print_report=True):
#     starts = eventlog.dropna(subset=['Timestamp']).sort_values('Timestamp').groupby('CaseID')['Timestamp'].first().to_dict()
#     diffs_by_act = {act:[] for act in eventlog['Event name'].unique()}
#     for _, row in eventlog.iterrows():
#         ts = row['Timestamp']; lt = row.get('Logtime', pd.NaT); act=row['Event name']
#         if pd.isna(ts): continue
#         if relative_to_start:
#             start = starts.get(row['CaseID'])
#             if pd.notna(start):
#                 diff = (ts-start).total_seconds()/60
#             else: continue
#         elif relative_to_log:
#             if pd.notna(lt):
#                 diff = (lt-ts).total_seconds()/60
#             else: continue
#         else:
#             diff = (ts - ts.normalize()).total_seconds()/60
#         diffs_by_act[act].append(diff)
#     stats = {}
#     for act, arr in diffs_by_act.items():
#         if len(arr)>=30:
#             stats[act] = (np.mean(arr), np.std(arr, ddof=0))
#     recs=[]; dev=0
#     for _, row in eventlog.dropna(subset=['Timestamp']).iterrows():
#         act=row['Event name']; ts=row['Timestamp']; lt=row.get('Logtime', pd.NaT)
#         if act not in stats: continue
#         m, s = stats[act]
#         if relative_to_start:
#             diff = (ts-starts[row['CaseID']]).total_seconds()/60
#         elif relative_to_log:
#             diff = (lt-ts).total_seconds()/60 if pd.notna(lt) else np.nan
#         else:
#             diff = (ts - ts.normalize()).total_seconds()/60
#         z = abs((diff-m)/s) if s>0 else np.nan
#         recs.append({'Case':row['CaseID'],'Event name':act,'Delay':diff,'Z':z})
#         if not np.isnan(z) and z>delta_z:
#             dev+=1
#     df = pd.DataFrame(recs)
#     if print_report:
#         label = "since start of trace" if relative_to_start else "between event and log" if relative_to_log else "since midnight"
#         print(f"There were {dev} events at an unusual time ({label}).")
#     return df

# def pattern_time_between_activities(eventlog, delta_z=3, print_details=False, print_report=True):
#     recs=[]
#     for case, sub in eventlog.dropna(subset=['Timestamp']).groupby('CaseID'):
#         ts = sub.sort_values('Timestamp')['Timestamp']
#         for t0, t1 in zip(ts, ts.shift(-1).dropna()):
#             recs.append({'Case':case,'Duration':(t1-t0).total_seconds()/60})
#     df = pd.DataFrame(recs)
#     if df.empty:
#         print("No inter-activity durations available.")
#         return df
#     m, s = df['Duration'].mean(), df['Duration'].std(ddof=0)
#     df['Z'] = df['Duration'].apply(lambda x: abs((x-m)/s) if s>0 else np.nan)
#     dev = int((df['Z']>delta_z).sum())
#     ncase = int(df[df['Z']>delta_z]['Case'].nunique())
#     if print_report:
#         print(f"There were {dev} events with unusual durations over {ncase} cases.")
#     return df

# # … include all other pattern_... functions from SWORDv4.ipynb here …
# # …

# def create_full_report(eventlog, delta_z=3, print_details=False, print_report=True):
#     # … your full createFullReport body …
#     cases = sorted(eventlog['CaseID'].unique())
#     rpt = pd.DataFrame({'CaseID':cases})
#     if print_details: print("\nPattern   -Frequent occurrence of activity-")
#     freq = pattern_activity_frequency(eventlog, delta_z, print_details, print_report)
#     rpt['z_Frequent'] = rpt['CaseID'].apply(
#         lambda c: freq[freq['Case']==c]['Z'].max() if not freq.empty else np.nan
#     )
#     if print_details: print("\nPattern   -Occurrence of directly repeating activity-")
#     rep = pattern_directly_repeating_activity(eventlog, print_details, print_report)
#     rpt['n_Repeats'] = rpt['CaseID'].apply(lambda c: rep[rep['Case']==c].shape[0])
#     # … and so on for the rest of your patterns …
#     rpt['z_Max'] = rpt.loc[:, rpt.columns.difference(['CaseID'])].max(axis=1, skipna=True)
#     return rpt

# if __name__ == "__main__":
#     try:
#         if len(sys.argv) < 2:
#                 print("Usage: python sword.py <path_to_csv_file>")
#                 sys.exit(1)

#         input_path = sys.argv[1]
#         if not os.path.exists(input_path):
#             print(f"File not found: {input_path}")
#             sys.exit(1)

#         base_name = Path(input_path).stem
#         output_dir = os.path.join(os.path.dirname(input_path), "output")
#         os.makedirs(output_dir, exist_ok=True)

#         logging.info(f"Processing file: {input_path}")
#         df = pd.read_csv(input_path)
#         logging.info(f"CSV loaded with {len(df)} rows")

#         # Data cleaning
#         df['CaseID'] = df.apply(
#             lambda r: r['Affected user'] if r['User full name'] == "-" else r['User full name'],
#             axis=1
#         )
#         df.drop(['User full name', 'Affected user'], axis=1, errors='ignore', inplace=True)

#         df['Event name'] = df.apply(
#             lambda r: f"{r['Event name']} {r['Description'].split('id ')[-1].split(')')[0]}"
#             if pd.notna(r['Description']) and 'id ' in r['Description'] else r['Event name'],
#             axis=1
#         )
#         df['Event name'] = df.apply(lambda r: f"{r['Component']}: {r['Event name']}", axis=1)

#         try:
#             df['Time'] = pd.to_datetime(df['Time'], format='%d/%m/%y, %H:%M')
#         except:
#             try:
#                 df['Time'] = pd.to_datetime(df['Time'], format='%d/%m/%y %H:%M')
#             except:
#                 df['Time'] = pd.to_datetime(df['Time'], dayfirst=True, errors='coerce')

#         df = df.dropna(subset=['Time']).sort_values('Time')
#         df.rename(columns={'Time': 'Timestamp'}, inplace=True)

#         pos = df.columns.get_loc('CaseID') + 1
#         df.insert(pos, 'Resource', '')
#         df.insert(pos + 1, 'Logtime', '')

#         # Fixing timestamp overlaps
#         td, pm, pn = 0, None, None
#         for i in range(1, len(df)):
#             ci = df.iloc[i]
#             mn = ci['Timestamp'].minute
#             nm = ci['CaseID']
#             if nm == pn and mn == pm:
#                 td += 1
#                 df.iat[i, df.columns.get_loc('Timestamp')] = df.iloc[i - 1]['Timestamp'] + timedelta(seconds=td)
#             else:
#                 td = 0
#             pn, pm = nm, mn

#         # Run report
#         logging.info("Running full SWORD report")
#         buf = io.StringIO()
#         with contextlib.redirect_stdout(buf):
#             print("#--Full SWORD Report--")
#             report = create_full_report(df)
#             print(report)

#         lines = buf.getvalue().splitlines()
#         json_out = os.path.join(output_dir, f"{base_name}_sword_output.json")
#         with open(json_out, 'w', encoding='utf-8') as f:
#             json.dump({"console_output": lines}, f, ensure_ascii=False, indent=4)

#         logging.info(f"✅ Report written to {json_out}")
#         logging.info("✅ Python script finished successfully")

#     except Exception as e:
#         logging.exception("❌ Error occurred during script execution")
#         print("An error occurred. Check log file for details.")