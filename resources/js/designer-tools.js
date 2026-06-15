(function () {
  function toggleJson() {
    const box = document.getElementById('json_box');
    if (!box) return;
    box.style.display = (box.style.display === 'none') ? 'block' : 'none';
    box.textContent = document.getElementById('schema_json').value;
  }

  function getSchemaObj() {
    return JSON.parse(document.getElementById('schema_json').value || '{}');
  }

  function saveSchemaObj(schemaObj) {
    document.getElementById('schema_json').value = JSON.stringify(schemaObj, null, 2);
    const jsonBox = document.getElementById('json_box');
    if (jsonBox && jsonBox.style.display !== 'none') jsonBox.textContent = document.getElementById('schema_json').value;
  }

  function uid(prefix = 'x') {
    return `${prefix}_${Date.now()}_${Math.floor(Math.random() * 1000)}`;
  }

  function designerRerender() {
    location.reload();
  }

  function setByPath(obj, path, value) {
    const parts = (path || '').split('.');
    let cur = obj;
    for (let i = 0; i < parts.length - 1; i++) {
      const key = parts[i];
      if (cur[key] === undefined) return false;
      cur = cur[key];
    }
    cur[parts[parts.length - 1]] = value;
    return true;
  }

  document.addEventListener('input', function (e) {
    if (!e.target.classList.contains('editable-text')) return;
    const path = e.target.getAttribute('data-path');
    const newText = e.target.innerText;
    const schemaObj = getSchemaObj();
    setByPath(schemaObj, path, newText);
    saveSchemaObj(schemaObj);
  });

  function ensureBlocks(schema) {
    schema.form = schema.form || {};
    schema.form.blocks = schema.form.blocks || [];
  }

  function findBlockIndexByKey(schema, key) {
    ensureBlocks(schema);
    return schema.form.blocks.findIndex(b => b && b.key === key);
  }

  function designerDeleteBlock(blockKey) {
    const schema = getSchemaObj();
    ensureBlocks(schema);
    const idx = findBlockIndexByKey(schema, blockKey);
    if (idx < 0) return alert('Block not found: ' + blockKey);
    schema.form.blocks.splice(idx, 1);
    saveSchemaObj(schema);
    designerRerender();
  }

  function designerMoveBlock(blockKey, dir) {
    const schema = getSchemaObj();
    ensureBlocks(schema);
    const idx = findBlockIndexByKey(schema, blockKey);
    if (idx < 0) return alert('Block not found: ' + blockKey);
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= schema.form.blocks.length) return;
    const tmp = schema.form.blocks[idx];
    schema.form.blocks[idx] = schema.form.blocks[newIdx];
    schema.form.blocks[newIdx] = tmp;
    saveSchemaObj(schema);
    designerRerender();
  }

  function designerAddBlock(blockObj) {
    const schema = getSchemaObj();
    ensureBlocks(schema);
    if (!blockObj.key) blockObj.key = uid('block');
    schema.form.blocks.push(blockObj);
    saveSchemaObj(schema);
    designerRerender();
  }

  function designerAddParagraph() {
    designerAddBlock({ type: "paragraph", key: uid("para"), text: "New paragraph..." });
  }

  function designerAddHeading() {
    designerAddBlock({ type: "heading", key: uid("heading"), level: 3, text: "New Heading" });
  }

  function designerAddTable() {
    designerAddBlock({
      type: "table",
      key: uid("table"),
      rows: [
        { type: "header", cells: [{ type: "th", text: "Column 1" }, { type: "th", text: "Column 2" }] },
        { cells: [{ type: "text", text: "" }, { type: "text", text: "" }] }
      ]
    });
  }

  function designerAddRepeatTable() {
    const rk = uid("repeat_table");
    designerAddBlock({
      type: "repeat_table",
      key: rk,
      table_id: rk,
      add_rows_control: { type: "number", id: uid("rowCount"), "default": 1, min: 1 },
      columns: ["Column 1", "Column 2"],
      row_schema: {
        col_1: { type: "text", class: "input", default: "" },
        col_2: { type: "text", class: "input", default: "" }
      },
      min_rows: 1,
      default_rows: [{ col_1: "", col_2: "" }]
    });
  }

  function designerTableAddColumn(blockKey, label = 'New Column') {
    const schema = getSchemaObj();
    const idx = findBlockIndexByKey(schema, blockKey);
    if (idx < 0) return alert('Table block not found: ' + blockKey);
    const block = schema.form.blocks[idx];
    if (block.type !== 'table') return alert('Not a table block');

    block.rows = block.rows || [];
    let header = block.rows.find(r => (r.type || '') === 'header');
    if (!header) {
      header = { type: 'header', cells: [] };
      block.rows.unshift(header);
    }
    header.cells = header.cells || [];
    header.cells.push({ type: 'th', text: label });

    for (const r of block.rows) {
      if ((r.type || '') === 'header') continue;
      r.cells = r.cells || [];
      r.cells.push({ type: 'text', text: '' });
    }

    saveSchemaObj(schema);
    designerRerender();
  }

  function slugFieldName(label) {
    return label.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  }

  function designerRepeatAddColumn(blockKey) {
    const label = prompt('Column label?');
    if (!label) return;

    const schema = getSchemaObj();
    const idx = findBlockIndexByKey(schema, blockKey);
    if (idx < 0) return alert('Repeat table not found: ' + blockKey);

    const block = schema.form.blocks[idx];
    if (block.type !== 'repeat_table') return alert('Not a repeat_table');

    block.columns = block.columns || [];
    block.row_schema = block.row_schema || {};
    block.default_rows = block.default_rows || [];

    const field = slugFieldName(label) || uid('col');
    const fieldKey = (block.row_schema[field]) ? `${field}_${Date.now()}` : field;

    block.columns.push(label);
    block.row_schema[fieldKey] = { type: "text", class: "input", default: "" };
    for (const r of block.default_rows) r[fieldKey] = r[fieldKey] ?? "";

    saveSchemaObj(schema);
    designerRerender();
  }

  // EXPOSE FUNCTIONS to window for onclick=""
  window.toggleJson = toggleJson;
  window.designerAddParagraph = designerAddParagraph;
  window.designerAddHeading = designerAddHeading;
  window.designerAddTable = designerAddTable;
  window.designerAddRepeatTable = designerAddRepeatTable;
  window.designerDeleteBlock = designerDeleteBlock;
  window.designerMoveBlock = designerMoveBlock;
  window.designerTableAddColumn = designerTableAddColumn;
  window.designerRepeatAddColumn = designerRepeatAddColumn;
})();
