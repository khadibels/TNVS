<?php
if (!isset($items)) {
    $items = [];
}
if (!isset($locations)) {
    $locations = [];
}
?>

<!-- Stock In -->
<div class="modal fade" id="inModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="../actions/stock_in.php">
      <div class="modal-header">
        <h5 class="modal-title">Stock In</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Item</label>
        <select name="item_id" class="form-select" required>
          <?php foreach ($items as $i): ?>
            <option value="<?= $i["id"] ?>"><?= htmlspecialchars(
    $i["sku"] . " — " . $i["name"]
) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label mt-2">Location</label>
        <div class="d-flex align-items-center gap-2">
          <select name="location_id" class="form-select" required>
            <?php foreach ($locations as $l): ?>
              <option value="<?= $l["id"] ?>"><?= htmlspecialchars(
    $l["name"]
) ?></option>
            <?php endforeach; ?>
          </select>
          
        </div>

        <label class="form-label mt-2">Quantity</label>
        <input type="number" name="qty" min="1" class="form-control" required>

        <label class="form-label mt-2">Note (optional)</label>
        <input type="text" name="note" class="form-control" placeholder="DR#123, supplier, etc.">
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Add Stock</button>
      </div>
    </form>
  </div>
</div>

<!-- Stock Out -->
<div class="modal fade" id="outModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="../actions/stock_out.php">
      <div class="modal-header">
        <h5 class="modal-title">Stock Out</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Item</label>
        <select name="item_id" class="form-select" required>
          <?php foreach ($items as $i): ?>
            <option value="<?= $i["id"] ?>"><?= htmlspecialchars(
    $i["sku"] . " — " . $i["name"]
) ?></option>
          <?php endforeach; ?>
        </select>

        <label class="form-label mt-2">From Location</label>
        <div class="d-flex align-items-center gap-2">
          <select name="location_id" class="form-select" required>
            <?php foreach ($locations as $l): ?>
              <option value="<?= $l["id"] ?>"><?= htmlspecialchars(
    $l["name"]
) ?></option>
            <?php endforeach; ?>
          </select>
          
        </div>

        <label class="form-label mt-2">Quantity</label>
        <input type="number" name="qty" min="1" class="form-control" required>

        <label class="form-label mt-2">Note (optional)</label>
        <input type="text" name="note" class="form-control" placeholder="SO/Job ref, reason…">
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-outline-primary">Deduct Stock</button>
      </div>
    </form>
  </div>
</div>

<!-- Transfer -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="../actions/stock_transfer.php">
      <div class="modal-header">
        <h5 class="modal-title">Transfer Stock</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Item</label>
        <select name="item_id" class="form-select" required>
          <?php foreach ($items as $i): ?>
            <option value="<?= $i["id"] ?>"><?= htmlspecialchars(
    $i["sku"] . " — " . $i["name"]
) ?></option>
          <?php endforeach; ?>
        </select>

        <div class="row">
          <div class="col-12 col-md-6">
            <label class="form-label mt-2">From</label>
            <div class="d-flex align-items-center gap-2">
              <select name="from_location_id" class="form-select" required>
                <?php foreach ($locations as $l): ?>
                  <option value="<?= $l["id"] ?>"><?= htmlspecialchars(
    $l["name"]
) ?></option>
                <?php endforeach; ?>
              </select>
              
            </div>
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label mt-2">To</label>
            <div class="d-flex align-items-center gap-2">
              <select name="to_location_id" class="form-select" required>
                <?php foreach ($locations as $l): ?>
                  <option value="<?= $l["id"] ?>"><?= htmlspecialchars(
    $l["name"]
) ?></option>
                <?php endforeach; ?>
              </select>
              
            </div>
          </div>
        </div>

        <label class="form-label mt-2">Quantity</label>
        <input type="number" name="qty" min="1" class="form-control" required>

        <label class="form-label mt-2">Note (optional)</label>
        <input type="text" name="note" class="form-control" placeholder="Transfer reason…">
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Transfer</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Location modal -->
<div class="modal fade" id="addLocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" id="formAddLoc">
      <div class="modal-header">
        <h5 class="modal-title">Add Location</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Location Name</label>
        <input name="name" class="form-control" required>
        <div class="text-danger small mt-2 d-none" id="locErr"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('formAddLoc').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const errEl = document.getElementById('locErr');
  errEl.classList.add('d-none');
  errEl.textContent = '';

  try {
    const fd = new FormData(ev.target);
    const res = await fetch('../actions/add_location.php', { method: 'POST', body: fd });
    const j   = await res.json();
    if (!res.ok || !j.ok) {
      errEl.classList.remove('d-none');
      errEl.textContent = j.error || 'Failed to add';
      return;
    }

    // Add & auto-select new location in every relevant select
    document.querySelectorAll('select[name="location_id"], select[name="from_location_id"], select[name="to_location_id"]').forEach(sel => {
      // avoid duplicate options if it already exists =)
      if (![...sel.options].some(o => o.value == j.id)) {
        const opt = new Option(j.name, j.id, true, true); 
        sel.add(opt);
      }
      sel.value = String(j.id);
    });

    // Close modal and reset form
    const m = bootstrap.Modal.getInstance(document.getElementById('addLocModal'));
    if (m) m.hide();
    ev.target.reset();

  } catch (e) {
    errEl.classList.remove('d-none');
    errEl.textContent = 'Network error';
  }
});
</script>
