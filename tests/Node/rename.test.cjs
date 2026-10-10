const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const app = process.env.MOD_RENAME_TEST_APP;
assert.ok(app, 'MOD_RENAME_TEST_APP must name an app with installed Babel and Vue parsers');
const helper = path.resolve(__dirname, '../../resources/js/rename/helper.cjs');
const old = 'app/Modules/Inventory/resources/js/pages/Widget/Show.vue';
const next = old.replace('Inventory', 'Catalog').replace('Widget', 'Gadget');
function run(files, overrides = {}) {
  const input = { version: 1, base: app, files, paths: { [old]: next }, aliases: { '@modules': 'app/Modules', '@': 'resources/js' }, identities: { 'Inventory::Widget/Show': 'Catalog::Gadget/Show' }, ...overrides };
  const result = spawnSync(process.execPath, [helper], { input: JSON.stringify(input), encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}
function body(file, result) {
  return result.edits.filter(e => e.file === file.path).sort((a,b)=>b.offset-a.offset).reduce((source,e) => {
    const bytes = Buffer.from(source); assert.equal(bytes.subarray(e.offset,e.offset+Buffer.byteLength(e.before)).toString(),e.before);
    return Buffer.concat([bytes.subarray(0,e.offset),Buffer.from(e.after),bytes.subarray(e.offset+Buffer.byteLength(e.before))]).toString();
  },file.source);
}
for (const kind of ['js','ts','jsx','tsx']) test(`${kind} static imports, exports and dynamic imports preserve user copy and UTF8 CRLF`, () => {
  const file = { path: `resources/js/Consumer.${kind}`, kind, source: `// 🍁\r\nimport Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue';\r\nexport { default } from '@modules/Inventory/resources/js/pages/Widget/Show.vue';\r\nconst page = import('@modules/Inventory/resources/js/pages/Widget/Show.vue');\r\nconst label = 'Inventory::Widget/Show';\r\n` + (kind.includes('x') ? 'const element = <div title="Inventory::Widget/Show"/>;\r\n' : '') };
  const result = run([file,{path:old,kind:'vue',source:'<template>Hi</template>'}]);
  assert.equal(result.edits.length,3); assert.ok(body(file,result).includes("const label = 'Inventory::Widget/Show'")); assert.ok(body(file,result).includes('@modules/Catalog/resources/js/pages/Gadget/Show.vue')); assert.ok(result.checklist.some(e=>e.category==='identity-string')); assert.ok(Object.keys(result.dependencies).length>1);
});
test('Vue script setup and template expressions are parsed and preserve labels',()=>{
  const file={path:'resources/js/Consumer.vue',kind:'vue',source:`<script setup lang="ts">\nimport Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue';\nconst label = 'Inventory::Widget/Show';\n</script>\n<template><div>{{ label }}</div></template>`};
  const result=run([file,{path:old,kind:'vue',source:'<template>Hi</template>'}]); assert.equal(result.edits.length,1); assert.ok(body(file,result).includes('Catalog/resources/js/pages/Gadget/Show.vue'));
});
test('moved importer preserves neighbouring relative imports and app aliases',()=>{
  const file={path:old,kind:'vue',source:`<script>import Near from './Near.vue'; import App from '@/pages/Inventory/Widget/Show.vue';</script>`};
  const neighbour={path:old.replace('Show.vue','Near.vue'),kind:'vue',source:'<template/>'};
  const appOld='resources/js/pages/Inventory/Widget/Show.vue'; const appNew=appOld.replace('Inventory','Catalog');
  const result=run([file,neighbour,{path:appOld,kind:'vue',source:'<template/>'}],{paths:{[old]:next,[appOld]:appNew}});
  assert.ok(body(file,result).includes('../../../Inventory/resources/js/pages/Widget/Near.vue')); assert.ok(body(file,result).includes('@/pages/Catalog/Widget/Show.vue')); assert.ok(!body(file,result).includes('@modules/../'));
});
test('malformed source rolls back every edit for the file',()=>{
  const file={path:'resources/js/bad.ts',kind:'ts',source:`import Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue'; const = ;`};
  const result=run([file]); assert.equal(result.edits.length,0); assert.ok(result.failures[file.path]);
});
test('computed imports, CSS URLs, unsupported Vue blocks stay checklist items',()=>{
  const file={path:'resources/js/Consumer.vue',kind:'vue',source:`<script setup>const page = import('@modules/Inventory/' + name);</script><template><div/></template><style>.a{background:url('/Widget.png')}</style>`};
  const result=run([file]); assert.equal(result.edits.length,0); assert.ok(result.checklist.some(e=>e.category==='dynamic-import')); assert.ok(result.checklist.some(e=>e.category==='css-asset'));
  assert.ok(run([{...file,source:'<script lang="coffee">x = 1</script>'}]).failures[file.path]);
});
test('missing app parsers cannot resolve global dependencies',()=>{
  const result=run([{path:'resources/js/a.ts',kind:'ts',source:'export {}'}],{base:'/tmp/mod-no-such-toolchain'}); assert.ok(result.failures['resources/js/a.ts']); assert.equal(result.edits.length,0);
});
test('protocol version is fail closed',()=>{
  const result=spawnSync(process.execPath,[helper],{input:JSON.stringify({version:2}),encoding:'utf8'}); assert.notEqual(result.status,0); assert.equal(result.stdout,'');
});
test('rewritten Vue and React fixtures compile with the app compilers',()=>{
  const { createRequire }=require('node:module'); const appRequire=createRequire(path.resolve(app,'package.json'));
  const vue=appRequire('@vue/compiler-sfc'), ts=appRequire('typescript');
  const file={path:'resources/js/Build.vue',kind:'vue',source:`<script setup lang="ts">import Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue'; const title: string = 'Inventory::Widget/Show';</script><template><Show/><p>{{ title }}</p></template>`};
  const result=run([file,{path:old,kind:'vue',source:'<template/>'}]);
  const {descriptor,errors}=vue.parse(body(file,result),{filename:file.path}); assert.deepEqual(errors,[]);
  const script=vue.compileScript(descriptor,{id:'build'}); assert.ok(script.content.includes('Catalog/resources/js/pages/Gadget/Show.vue'));
  assert.deepEqual(vue.compileTemplate({source:descriptor.template.content,filename:file.path,id:'build'}).errors,[]);
  for (const kind of ['jsx','tsx']) {
    const react={path:`resources/js/Build.${kind}`,kind,source:`import Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue'; export default function Page(){ return <Show title="Inventory::Widget/Show"/> }`};
    const response=run([react,{path:old,kind:'vue',source:'<template/>'}]);
    const compiled=ts.transpileModule(body(react,response),{fileName:react.path,reportDiagnostics:true,compilerOptions:{jsx:ts.JsxEmit.ReactJSX,target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ESNext}});
    assert.deepEqual(compiled.diagnostics,[]); assert.ok(compiled.outputText.includes('Catalog/resources/js/pages/Gadget/Show.vue')); assert.ok(compiled.outputText.includes('jsx'));
  }
});
test('Vue static component identity uses template AST and preserves neighbouring user copy',()=>{
  const file={path:'resources/js/Component.vue',kind:'vue',source:`<template><component is="WidgetCard"/><div title="WidgetCard"/><component :is="selected"/></template>`};
  const result=run([file],{components:{WidgetCard:'GadgetCard'}});
  assert.equal(result.edits.length,1); assert.equal(body(file,result),file.source.replace('is="WidgetCard"','is="GadgetCard"')); assert.ok(result.checklist.some(e=>e.category==='identity-string'));
});
test('Babel-only app rewrites supported files and falls back only for Vue without installing anything',()=>{
  const fs=require('node:fs'), os=require('node:os'), crypto=require('node:crypto');
  const base=fs.mkdtempSync(path.join(os.tmpdir(),'mod-rename-parser-'));
  try {
    fs.mkdirSync(path.join(base,'node_modules','@babel'),{recursive:true}); fs.cpSync(path.join(app,'node_modules','@babel','parser'),path.join(base,'node_modules','@babel','parser'),{recursive:true});
    fs.writeFileSync(path.join(base,'package.json'),'{"private":true}');
    const before=crypto.createHash('sha256').update(fs.readFileSync(path.join(base,'package.json'))).digest('hex');
    const file={path:'resources/js/a.ts',kind:'ts',source:`import Show from '@modules/Inventory/resources/js/pages/Widget/Show.vue';`};
    const result=run([file,{path:old,kind:'vue',source:'<template><p>Hi</p></template>'}],{base});
    assert.equal(result.edits.length,1,JSON.stringify(result.failures)); assert.ok(result.failures[old]); assert.ok(!result.failures[file.path]);
    assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(base,'package.json'))).digest('hex'),before);
    assert.deepEqual(fs.readdirSync(base).sort(),['node_modules','package.json']); assert.deepEqual(fs.readdirSync(path.join(base,'node_modules')).sort(),['@babel']);
  } finally { fs.rmSync(base,{recursive:true,force:true}); }
});
test('extensionless imports, nested names and alias escapes are resolved conservatively',()=>{
  const source='app/Modules/Inventory/resources/js/components/Archived/WidgetCard.tsx', destination='app/Modules/Catalog/resources/js/components/Archived/GadgetCard.tsx';
  const file={path:'resources/js/consumer.tsx',kind:'tsx',source:`import Card from '@modules/Inventory/resources/js/components/Archived/WidgetCard'; import Bad from '@modules/../outside'; export default () => <Card/>;`};
  const result=run([file,{path:source,kind:'tsx',source:'export default () => <p/>;'}],{paths:{[source]:destination}});
  assert.equal(result.edits.length,1); assert.ok(body(file,result).includes('@modules/Catalog/resources/js/components/Archived/GadgetCard')); assert.ok(result.checklist.some(e=>e.category==='frontend-import'));
});
test('computed identities and unsupported require/path-string contexts remain located checklist items',()=>{
  const file={path:'resources/js/references.ts',kind:'ts',source:"const page = 'Inventory::' + name;\nconst template = `Inventory::${name}`;\nconst legacy = require('@modules/Inventory/resources/js/pages/Widget/Show.vue');\nconst label = '@modules/Inventory/resources/js/pages/Widget/Show.vue';"};
  const result=run([file,{path:old,kind:'vue',source:'<template/>'}]);
  assert.equal(result.edits.length,0); assert.deepEqual([...new Set(result.checklist.map(row=>row.line))].sort(),[1,2,3,4]);
});
test('unsupported installed parser metadata is retained as an immutable read dependency',()=>{
  const fs=require('node:fs'), os=require('node:os'), crypto=require('node:crypto');
  const base=fs.mkdtempSync(path.join(os.tmpdir(),'mod-rename-version-'));
  try {
    fs.mkdirSync(path.join(base,'node_modules','@babel'),{recursive:true}); fs.cpSync(path.join(app,'node_modules','@babel','parser'),path.join(base,'node_modules','@babel','parser'),{recursive:true});
    const metadata=path.join(base,'node_modules','@babel','parser','package.json'); const data=JSON.parse(fs.readFileSync(metadata,'utf8')); data.version='7.30.0'; fs.writeFileSync(metadata,JSON.stringify(data));
    const file={path:'resources/js/a.ts',kind:'ts',source:'export {}'};
    const result=run([file],{base}); assert.equal(result.edits.length,0); assert.ok(result.failures[file.path].includes('Unsupported @babel/parser'));
    assert.equal(result.dependencies[fs.realpathSync(metadata)],crypto.createHash('sha256').update(fs.readFileSync(metadata)).digest('hex'));
  } finally { fs.rmSync(base,{recursive:true,force:true}); }
});
