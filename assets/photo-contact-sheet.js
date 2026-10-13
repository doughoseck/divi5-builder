#!/usr/bin/env node
// Choosing pictures from a client's photo dump: number every picture, make small numbered thumbnails and tiled contact
// sheets (ffmpeg, which reads 20 MB camera JPGs quickly), write index.json (number -> file, pixel size, MB). Look at the
// sheets, recommend by number, and let the owner answer with numbers. HEIC files are skipped (ffmpeg builds rarely read them).
//   node photo-contact-sheet.js <source-dir> <out-dir>
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process');
const [SRC, OUT] = process.argv.slice(2); if (!SRC || !OUT) { console.error('usage: node photo-contact-sheet.js <source-dir> <out-dir>'); process.exit(1); }
const TH = path.join(OUT, 'thumbs'), SH = path.join(OUT, 'sheets'); fs.mkdirSync(TH, { recursive: true }); fs.mkdirSync(SH, { recursive: true });
const walk = d => fs.readdirSync(d, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true })).flatMap(e => e.isDirectory() ? walk(path.join(d, e.name)) : /\.(jpe?g|png)$/i.test(e.name) ? [path.join(d, e.name)] : []);
const files = walk(SRC); const items = [];
const probe = p => { try { return execFileSync('ffprobe', ['-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', p], { encoding: 'utf8' }).trim(); } catch (e) { return '?'; } };
files.forEach((f, i) => {
  const n = i + 1, label = String(n).padStart(3, '0'), dst = path.join(TH, label + '.jpg');
  const it = { n, file: path.relative(SRC, f).replace(/\\/g, '/'), group: path.dirname(path.relative(SRC, f)).replace(/\\/g, '/'), dims: probe(f), mb: +(fs.statSync(f).size / 1048576).toFixed(1) };
  items.push(it); if (fs.existsSync(dst)) return;
  // fit inside 400x267 on black, number top-left; crop guards against the one-pixel rounding that makes pad fail
  const vf = `scale=400:267:force_original_aspect_ratio=decrease,crop='min(iw,400)':'min(ih,267)',pad=400:267:(ow-iw)/2:(oh-ih)/2:black,drawtext=fontfile='C\\:/Windows/Fonts/arialbd.ttf':text='${label}':x=8:y=8:fontsize=34:fontcolor=white:box=1:boxcolor=black@0.6:boxborderw=6`;
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-i', f, '-vf', vf, '-q:v', '4', dst]);
});
fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify(items, null, 1));
// sheets of 12 (4 x 3) per folder; the concat demuxer needs every entry twice (file + duration) or the first sheet comes out short
const groups = [...new Set(items.map(i => i.group))];
for (const g of groups) {
  const mine = items.filter(i => i.group === g);
  for (let s = 0; s * 12 < mine.length; s++) {
    const chunk = mine.slice(s * 12, s * 12 + 12), list = path.join(SH, 'list.txt');
    fs.writeFileSync(list, chunk.map(i => `file '${path.join(TH, String(i.n).padStart(3, '0') + '.jpg').replace(/\\/g, '/')}'\nduration 1`).join('\n') + '\n');
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', list, '-vf', 'tile=4x3:padding=4:color=white', '-frames:v', '1', '-q:v', '3', path.join(SH, `${g.replace(/[^\w]+/g, '_') || 'root'}-${s + 1}.jpg`)]);
  }
}
try { fs.unlinkSync(path.join(SH, 'list.txt')); } catch (e) {}
console.log(items.length, 'pictures numbered;', fs.readdirSync(SH).length, 'sheets in', SH, '(look at the sheets with the Read tool; a sheet that shows fewer pictures than expected: read the thumbnails directly)');
