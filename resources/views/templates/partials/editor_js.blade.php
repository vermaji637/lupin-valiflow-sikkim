<script>
let selectedCell = null;

document.addEventListener('click', (e) => {
  const cell = e.target.closest('#editor td, #editor th');
  if (!cell) return;
  selectedCell = cell;
  document.querySelectorAll('#editor td, #editor th').forEach(c => c.style.outline = '');
  cell.style.outline = '2px solid #0d6efd';
});

function enableEdit(){
  document.querySelectorAll('#editor h1,h2,h3,h4,h5,h6,p,li,td,th,span,strong,b')
    .forEach(el => el.contentEditable="true");
  document.querySelectorAll('#editor td.criteria').forEach(td => td.contentEditable="true");
}
function disableEdit(){
  document.querySelectorAll('#editor [contenteditable="true"]').forEach(el => el.contentEditable="false");
}

function selectedTable(){
  return selectedCell ? selectedCell.closest('table') : null;
}

function addRow(){
  const t = selectedTable();
  if(!t) return alert('Select a table cell');
  const currentRow = selectedCell ? selectedCell.closest('tr') : null;
  if(!currentRow) return alert('Select a row');

  const clone = currentRow.cloneNode(true);
  clone.querySelectorAll('input,textarea').forEach(el => el.type==='checkbox' ? el.checked=false : el.value='');
  clone.querySelectorAll('select').forEach(sel => sel.selectedIndex=0);
  currentRow.after(clone);

  const nextCellIndex = Math.min(selectedCell.cellIndex, clone.children.length - 1);
  const nextSelected = clone.children[nextCellIndex] || clone.children[0];
  if (nextSelected) {
    document.querySelectorAll('#editor td, #editor th').forEach(c => c.style.outline = '');
    nextSelected.style.outline = '2px solid #0d6efd';
    selectedCell = nextSelected;
  }

  clone.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function delRow(){
  if(!selectedCell) return alert('Select a row');
  const row = selectedCell.closest('tr');
  const t = row.closest('table');
  if(t.querySelectorAll('tr').length <= 2) return alert('Cannot delete last row');
  row.remove();
  selectedCell=null;
}

function addCol(){
  const t = selectedTable();
  if(!t) return alert('Select a cell');
  const idx = selectedCell.cellIndex;

  t.querySelectorAll('tr').forEach(tr => {
    const isHeader = tr.querySelectorAll('th').length>0;
    const cell = document.createElement(isHeader ? 'th':'td');
    if(isHeader){
      cell.textContent='NEW COLUMN';
      cell.contentEditable="true";
    }else{
      const uniq='col_'+Date.now()+'_'+Math.floor(Math.random()*1000);
      cell.innerHTML=`<input class="input" name="${uniq}">`;
    }
    if(tr.children[idx]) tr.children[idx].after(cell);
    else tr.appendChild(cell);
  });
}

function delCol(){
  const t = selectedTable();
  if(!t) return alert('Select a cell');
  const idx = selectedCell.cellIndex;
  const cols = t.querySelector('tr')?.children?.length ?? 0;
  if(cols<=1) return alert('Cannot delete last column');

  t.querySelectorAll('tr').forEach(tr => tr.children[idx]?.remove());
  selectedCell=null;
}

function beforeSave(){
  // store criteria text inside data-text (for your copyCriteria feature)
  document.querySelectorAll('#editor td.criteria').forEach(td => td.dataset.text=(td.innerText||'').trim());
  document.getElementById('body_html').value=document.getElementById('editor').innerHTML;
}
</script>
