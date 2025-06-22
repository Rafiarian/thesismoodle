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
    try:
        drop_names = [
            "Mahendrawathi Er","Ika Nurkasanah","Andre Parvian Aristio","Admin User","Admin","-",
            "Akhtar Ibrahim","Athallah Hilman Hilalazka","Web Service",
            "Fabio Andrea Liui","Ichsan Ramadhan",
            "Maritza Dara Athifa","M. Khadavi Khalid","Mudjahidin",
            "Muhammad Sandhika Setiawan","Raid Orlando Azurae","Rafsyah Fachri Isfansyah",
            "Shakila Putri Damarista ","Veronika Stefani Pangaribuan"
        ]
        df = df[~df["User full name"].str.contains('|'.join(drop_names), case=False, na=False)].copy()

        drop_comps = [
            "Overview report","Submission comments","Grader report","Logs",
            "User report","Zoom meeting","Forum","Level Up XP","System"
        ]
        df = df[~df["Component"].isin(drop_comps)].copy()
        return df
    except Exception as e:
        print(f"Error cleaning data: {e}")
        return None
    

# —————————————————————————————
# 2) Main Execution Block (converted for CLI & JSON output)
# —————————————————————————————

def pattern_activity_frequency(eventlog, delta_z=3, print_details=False, print_report=True):
    cases = sorted(eventlog['CaseID'].unique())
    acts = sorted(eventlog['Event name'].unique())
    total_dev = 0
    for act in acts:
        freqs = eventlog[eventlog['Event name']==act].groupby('CaseID').size().reindex(cases, fill_value=0)
        m, s = freqs.mean(), freqs.std(ddof=1)
        if print_details:
            print(f"Activity {act}: Mean={m:.6f}, SD={s:.6f}")
        for case in cases:
            f = int(freqs.loc[case])
            z = abs((f - m) / s) if s > 0 else np.nan
            if z > delta_z and not (f == 1 and m < 1):
                print(f"Activity \"{act}\" occured {f} times in {case} (Mean={m:.6f}, SD={s:.6f}, Z={z:.3f})")
                total_dev += 1
    if print_report:
        print_frequency_report_summary(total_dev)
    return total_dev

def print_frequency_report_summary(total_deviations):
    return f"There were {total_deviations} events with an unusual frequency."

def get_unusual_activity_lines(eventlog, delta_z=3):
    cases = sorted(eventlog['CaseID'].unique())
    acts = sorted(eventlog['Event name'].unique())
    results = []
    for act in acts:
        freqs = eventlog[eventlog['Event name'] == act].groupby('CaseID').size().reindex(cases, fill_value=0)
        m, s = freqs.mean(), freqs.std(ddof=1)
        for case in cases:
            f = int(freqs.loc[case])
            z = abs((f - m) / s) if s > 0 else np.nan
            if z > delta_z and not (f == 1 and m < 1):
                line = f'Activity "{act}" occured {f} times in {case} (Mean={m:.6f}, SD={s:.6f}, Z={z:.3f})'
                results.append(line)
    return results

def classify_unusual_activities(unusual_lines, templates):
    classified = []
    for line in unusual_lines:
        try:
            activity_part = line.split(" occured ")[0].replace('Activity "', '').replace('"', '')
            user_part = line.split(" in ")[1].split(" (Mean")[0]
            occurred_count = int(line.split(" occured ")[1].split(" times in ")[0])
            mean_value = float(line.split("Mean=")[1].split(",")[0])
            arti, penyebab = "-", "-"
            for keyword, detail in templates.items():
                if keyword in activity_part:
                    arti = detail['arti']
                    penyebab = detail['penyebab']
                    break
            classified.append({
                "Nama": user_part,
                "Aktivitas": activity_part,
                "Jumlah Terjadi": occurred_count,
                "Rata-rata": mean_value,
                "Arti": arti,
                "Penyebab": penyebab
            })
        except Exception as e:
            print(f"Line skipped due to parsing error: {line} | {e}")
    return classified

activity_templates = {
    'mod_quiz: course module viewed': {
        'arti': 'Mahasiswa aktif dalam mengecek informasi kuis, seperti instruksi atau waktu pelaksanaan',
        'penyebab': 'Memiliki kepedulian tinggi terhadap informasi teknis pelaksanaan kuis atau ingin memastikan tidak ada informasi yang terlewat'
    },
    'mod_quiz: attempt viewed': {
        'arti': 'Mahasiswa melakukan pergantian halaman kuis lebih banyak, karena terdapat pertanyaan yang belum dijawab atau untuk meneliti jawabannya agar tidak ada kesalahan',
        'penyebab': 'Adanya keraguan terhadap jawaban atau strategi untuk memastikan semua soal telah dijawab dengan benar'
    },
    'mod_quiz: attempt started': {
        'arti': 'Mahasiswa lebih sering melaksanakan suatu kuis, karena memiliki inisiatif untuk mengasah kompetensi.',
        'penyebab': 'Proaktif terhadap penilaian dan pengembangan kompetensi'
    },
    'mod_quiz: attempt summary viewed': {
        'arti': 'Mahasiswa lebih sering melihat ringkasan hasil kuis, memastikan tidak ada yang terlewat sebelum dikumpulkan jawabannya.',
        'penyebab': 'Berhati-hati dan teliti sebelum mengumpulkan kuis'
    },
    'mod_quiz: attempt submitted': {
        'arti': 'Mahasiswa lebih sering mengumpulkan kuis yang dikerjakan, karena bertanggung jawab atas sesi kuis yang telah dimulai.',
        'penyebab': 'Disiplin dan menyelesaikan evaluasi sesuai waktu'
    },
    'mod_quiz: attempt reviewed': {
        'arti': 'Mahasiswa reflektif dan berusaha mengevaluasi performanya berdasarkan hasil kuis formative yang diberikan',
        'penyebab': 'Ingin memahami kesalahan dan memperbaiki performa pada quiz summative'
    },
    'mod_quiz: question viewed': {
        'arti': 'Mahasiswa memperhatikan soal secara detail, mungkin berpindah-pindah halaman untuk memahami atau mengecek kembali',
        'penyebab': 'Menunjukkan kehati-hatian dalam memahami soal atau strategi menjawab dengan memastikan tidak ada pertanyaan yang terlewat atau disalahpahami'
    },
    'mod_resource: course module viewed': {
        'arti': 'Mahasiswa aktif mengakses file materi pembelajaran, menandakan keterlibatan dalam proses belajar mandiri dari sumber utama.',
        'penyebab': 'Tertarik belajar dari materi PDF, PPT, atau dokumen yang disediakan dosen'
    },
    'mod_url: course module viewed': {
        'arti': 'Mahasiswa aktif mengakses materi pembelajaran berbasis video atau konten multimedia lain yang disediakan dosen, menandakan inisiatif untuk memahami materi dari sumber utama.',
        'penyebab': 'Tertarik pada format video sebagai sarana pembelajaran'
    },
    'mod_book: course module viewed': {
        'arti': 'Mahasiswa sering melakukan akses pembukaan modul buku dan sering membaca materi secara terstruktur dan sistematis',
        'penyebab': 'Menyukai pembelajaran berbasis struktur buku'
    },
    'core: course module completion updated': {
        'arti': 'Mahasiswa secara konsisten menandai modul sebagai selesai, yang bisa menunjukkan kedisiplinan atau kepedulian terhadap progres belajarnya. Namun, ini juga bisa terjadi karena terbiasa mencentang tanpa keterkaitan langsung dengan pemahaman materi.',
        'penyebab': 'Disiplin atau terbiasa melakukan checklist progres pembelajaran'
    }
}

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
        
    total_deviations = pattern_activity_frequency(df_final, delta_z=3, print_details=False, print_report=False)    
    unusual_activities = get_unusual_activity_lines(df_final, 3)
    # Capture summaries/detections per pattern
    patterns = [
        ("Frequent occurrence of activity", lambda: unusual_activities),
        ("Number of unusual activities", lambda: [print_frequency_report_summary(total_deviations)]),
        ("Unusual activity (classified)", lambda: classify_unusual_activities(unusual_activities, activity_templates))
    ]

    sword_results = {}
for title, runner in patterns:
    result = runner()

    if isinstance(result, list):
        sword_results[title] = result
    elif isinstance(result, dict):
        sword_results[title] = result
    else:
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            print(f"\nPattern   -{title}-\n")
            runner()
        sword_results[title] = buf.getvalue().splitlines()

    base = os.path.splitext(os.path.basename(INPUT_FILE))[0]
    base = base.replace("input", "output")
    json_path = os.path.join(OUTPUT_DIR, f"{base}.json")
    with open(json_path, 'w', encoding='utf-8') as jf:
        json.dump(sword_results, jf, ensure_ascii=False, indent=2)

    print(f"SWORD patterns JSON saved to {json_path}")
    print("All files processed!")

