'use strict';
// Protocol v1: immutable source in, byte edits/checklist/read dependencies out.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const Module = require('node:module');
const { createRequire } = Module;
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; if (Buffer.byteLength(input) > 32 * 1024 * 1024) process.exit(2); });
process.stdin.on('end', () => {
    try { process.stdout.write(JSON.stringify(plan(JSON.parse(input)))); }
    catch (error) { process.stderr.write(String(error.message).slice(0, 2000)); process.exitCode = 2; }
});
function plan(input) {
    if (input.version !== 1 || !path.isAbsolute(input.base) || !Array.isArray(input.files)) throw new Error('Unsupported rename helper protocol');
    const output = { version: 1, edits: [], checklist: [], failures: {}, dependencies: {} };
    const logicalRoot = path.resolve(input.base, 'node_modules');
    const root = fs.existsSync(logicalRoot) ? fs.realpathSync(logicalRoot) : logicalRoot;
    const appRequire = createRequire(path.resolve(input.base, 'package.json'));
    const dependency = file => {
        file = fs.realpathSync(file);
        const hash = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
        if (output.dependencies[file] && output.dependencies[file] !== hash) throw new Error('Parser dependency changed during parsing');
        output.dependencies[file] = hash;
    };
    const metadataNames = ['package.json','package-lock.json','pnpm-lock.yaml','yarn.lock','bun.lock','bun.lockb','node_modules/.package-lock.json','node_modules/.modules.yaml'];
    for (const name of metadataNames) { const file=path.resolve(input.base,name); if(fs.existsSync(file)) dependency(file); }
    // Observe module reads before the loader consumes them; verify again at completion.
    for (const [extension, loader] of Object.entries(Module._extensions)) Module._extensions[extension] = (module, filename) => {
        if (!fs.realpathSync(filename).startsWith(root+path.sep)) throw new Error('Parser dependency is outside the app toolchain');
        dependency(filename);
        return loader(module, filename);
    };
    function load(name, supported) {
        const entry = appRequire.resolve(name);
        const metadata = appRequire.resolve(`${name}/package.json`);
        for (const file of [entry, metadata]) if (!fs.realpathSync(file).startsWith(root + path.sep)) throw new Error(`Parser ${name} is outside the app toolchain`);
        dependency(metadata);
        dependency(entry);
        const version = JSON.parse(fs.readFileSync(metadata, 'utf8')).version;
        if (!supported.test(version)) throw new Error(`Unsupported ${name} ${version}`);
        return appRequire(name);
    }
    const known = new Set(input.inventory || input.files.map(file => file.path));
    const aliases = Object.entries(input.aliases || {}).sort((a,b) => b[0].length-a[0].length);
    function specifier(file, value) {
        const alias = aliases.find(([name]) => value.startsWith(name + '/'));
        if (!alias && !value.startsWith('./') && !value.startsWith('../')) return null;
        if (alias && value.split('/').includes('..')) return null;
        const absolute = alias ? path.posix.normalize(alias[1] + '/' + value.slice(alias[0].length + 1)) : path.posix.normalize(path.posix.dirname(file) + '/' + value);
        if (absolute.startsWith('../') || path.posix.isAbsolute(absolute)) return null;
        const candidates = [absolute, ...['.vue','.js','.ts','.jsx','.tsx','.mjs','.cjs','/index.js','/index.ts','/index.tsx','/index.jsx'].map(ext=>absolute+ext)].filter(candidate=>known.has(candidate));
        if (candidates.length !== 1) return null;
        const resolved = candidates[0], next = input.paths[resolved] || resolved;
        const suffix = resolved.slice(absolute.length);
        let target = next;
        if (suffix && target.endsWith(suffix)) target = target.slice(0,-suffix.length);
        if (alias) {
            if (!target.startsWith(alias[1] + '/')) return null;
            return alias[0] + '/' + target.slice(alias[1].length + 1);
        }
        const relative = path.posix.relative(path.posix.dirname(input.paths[file] || file), target);
        return relative.startsWith('.') ? relative : './' + relative;
    }
    for (const file of input.files) {
        const edits = [], checklist = [];
        const bytes = offset => Buffer.byteLength(file.source.slice(0,offset));
        const line = offset => file.source.slice(0,offset).split('\n').length;
        const review = (offset, category, message, suggestion = null) => checklist.push({ file:file.path, line:line(offset), category, message, suggestion });
        function literal(node, base) {
            const offset = base + node.start;
            const original = file.source.slice(offset,base+node.end);
            // Escaped strings are valid syntax but are deliberately not re-encoded.
            if (!['"',"'"].includes(original[0]) || original.slice(1,-1) !== node.value) { review(offset,'frontend-import','Escaped import requires review.'); return; }
            const after = specifier(file.path,node.value);
            if (after === null) { review(offset,'frontend-import','Import cannot be resolved unambiguously within the configured roots.'); return; }
            if (after !== node.value) edits.push({ file:file.path, offset:bytes(offset+1), before:node.value, after, line:line(offset+1), category:'frontend-import' });
        }
        function parseJS(source, base, lang) {
            const babel = load('@babel/parser', /^7\.29\./);
            const ast = babel.parse(source,{sourceType:'unambiguous',plugins:[...(lang === 'ts' || lang === 'tsx' ? ['typescript'] : []),...(lang === 'jsx' || lang === 'tsx' ? ['jsx'] : [])],createImportExpressions:true});
            function visit(node,parent,key) {
                if (!node || typeof node !== 'object') return;
                if (['ImportDeclaration','ExportNamedDeclaration','ExportAllDeclaration'].includes(node.type) && node.source) literal(node.source,base);
                if (node.type === 'ImportExpression') {
                    if (node.source.type === 'StringLiteral') literal(node.source,base);
                    else review(base+node.start,'dynamic-import','Computed import requires review.');
                }
                const importSource = key === 'source' && parent && ['ImportDeclaration','ExportNamedDeclaration','ExportAllDeclaration','ImportExpression'].includes(parent.type);
                if (node.type === 'StringLiteral' && !importSource) {
                    const intendedPath = specifier(file.path,node.value);
                    const intendedIdentity = input.identities[node.value] || input.components?.[node.value];
                    if (intendedIdentity || (intendedPath !== null && intendedPath !== node.value)) review(base+node.start,'identity-string','Unsupported identity context is unchanged.',intendedIdentity || intendedPath);
                }
                const prefixes = node.type === 'BinaryExpression' && node.operator === '+' ? [node.left,node.right].filter(child=>child.type === 'StringLiteral').map(child=>child.value)
                    : node.type === 'TemplateLiteral' ? node.quasis.map(part=>part.value.cooked || part.value.raw) : [];
                if (prefixes.some(prefix=>prefix.length>2 && Object.keys(input.identities).some(identity=>identity.startsWith(prefix)))) review(base+node.start,'identity-string','Computed identity requires review.');
                for (const [childKey,child] of Object.entries(node)) {
                    if (['loc','comments','tokens','extra'].includes(childKey)) continue;
                    if (Array.isArray(child)) child.forEach(item=>visit(item,node,childKey));
                    else if (child && typeof child === 'object') visit(child,node,childKey);
                }
            }
            visit(ast,null,null);
        }
        try {
            if (file.kind === 'vue') {
                const vue = load('@vue/compiler-sfc', /^3\.5\./);
                const { descriptor, errors } = vue.parse(file.source,{filename:file.path});
                if (errors.length) throw new Error(String(errors[0]));
                if (descriptor.customBlocks.length) throw new Error('Custom Vue blocks require review');
                for (const block of [descriptor.script,descriptor.scriptSetup].filter(Boolean)) {
                    const lang = block.lang || 'js';
                    if (block.src || !['js','ts','jsx','tsx'].includes(lang)) throw new Error('Unsupported Vue script language or external script');
                    parseJS(block.content,block.loc.start.offset,lang);
                }
                if (descriptor.template) {
                    const block=descriptor.template;
                    if (block.src || (block.lang && block.lang !== 'html')) throw new Error('Unsupported Vue template');
                    const template = vue.compileTemplate({source:block.content,filename:file.path,id:'mod-rename',compilerOptions:{expressionPlugins:descriptor.scriptSetup?.lang === 'ts' || descriptor.script?.lang === 'ts' ? ['typescript'] : []}});
                    if (template.errors.length) throw new Error(String(template.errors[0]));
                    const identitiesUsed = new Set();
                    function visitTemplate(node) {
                        if (!node || typeof node !== 'object') return;
                        if (node.type === 1 && node.tag === 'component') for (const prop of node.props) {
                            if (prop.type === 6 && prop.name === 'is' && prop.value && input.components?.[prop.value.content]) {
                                const original = prop.value.loc.source;
                                const value = prop.value.content;
                                const target = input.components[value];
                                const offset = block.loc.start.offset + prop.value.loc.start.offset;
                                if (['"', "'"].includes(original[0]) && original.slice(1,-1) === value && !/["'\\]/.test(target)) {
                                    edits.push({file:file.path,offset:bytes(offset+1),before:value,after:target,line:line(offset+1),category:'frontend-identity'});
                                    identitiesUsed.add(offset+1);
                                } else review(offset,'identity-string','Component identity requires review.',target);
                            } else if (prop.type === 7 && prop.name === 'bind' && prop.arg?.content === 'is') review(block.loc.start.offset+prop.loc.start.offset,'identity-string','Computed component identity requires review.');
                        }
                        for (const child of node.children || []) visitTemplate(child);
                        for (const branch of node.branches || []) visitTemplate(branch);
                    }
                    visitTemplate(template.ast);
                    for (const [identity,target] of Object.entries(input.components || {})) {
                        let index=block.content.indexOf(identity);
                        while(index>=0) { const offset=block.loc.start.offset+index; if(!identitiesUsed.has(offset)) review(offset,'identity-string','Unsupported template component identity is unchanged.',target); index=block.content.indexOf(identity,index+identity.length); }
                    }
                    // Template expressions are validated by Vue. Literal identity copy remains advisory.
                    for (const [identity,target] of Object.entries(input.identities)) {
                        let index=block.content.indexOf(identity);
                        while(index>=0) { review(block.loc.start.offset+index,'identity-string','Unsupported template identity context is unchanged.',target); index=block.content.indexOf(identity,index+identity.length); }
                    }
                }
                for (const block of descriptor.styles) for (const match of block.content.matchAll(/url\s*\(/g)) review(block.loc.start.offset+match.index,'css-asset','CSS asset URLs require review.');
            } else if (file.kind === 'css') {
                for (const match of file.source.matchAll(/url\s*\(/g)) review(match.index,'css-asset','CSS asset URLs require review.');
            } else parseJS(file.source,0,file.kind);
            output.edits.push(...edits); output.checklist.push(...checklist);
        } catch(error) { output.failures[file.path]=String(error.message).slice(0,2000); }
    }
    // Hash every module actually read, including parser transitive dependencies.
    for (const file of Object.keys(require.cache)) if (file.startsWith(root+path.sep)) dependency(file);
    for (const file of Object.keys(output.dependencies)) dependency(file);
    return output;
}
