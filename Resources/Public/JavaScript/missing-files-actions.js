import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Modal from "@typo3/backend/modal.js";
import Notification from "@typo3/backend/notification.js";
import { SeverityEnum } from "@typo3/backend/enum/severity.js";

function t(key, fallback) {
  return top.TYPO3?.lang?.[key] || fallback;
}

document.addEventListener("click", (event) => {
  const target = event.target instanceof Element ? event.target : null;
  const bulkButton = target?.closest("[data-faltools-bulk-delete]");
  if (bulkButton instanceof HTMLButtonElement) {
    event.preventDefault();
    handleBulkDelete(bulkButton);
    return;
  }

  const restoreButton = target?.closest("[data-faltools-restore-file]");
  if (restoreButton instanceof HTMLButtonElement) {
    event.preventDefault();
    openRestoreDialog(restoreButton);
    return;
  }

  const button = target?.closest("[data-faltools-delete-file]");
  if (!(button instanceof HTMLButtonElement)) {
    return;
  }

  event.preventDefault();
  const referenceCount = Number.parseInt(button.dataset.referenceCount || "0", 10);
  if (referenceCount > 0) {
    confirmReferencedDelete(button);
    return;
  }

  deleteMissingFile(button, false);
});

function handleBulkDelete(button) {
  const scope = button.dataset.scope || "page";
  const isFolderScope = scope === "folder";
  const uids = isFolderScope ? [] : parseUidList(button.dataset.uids || "");
  const recordCount = Number.parseInt(button.dataset.recordCount || "0", 10);
  if (!isFolderScope && uids.length === 0) {
    showNoRecordsWarning();
    return;
  }
  if (isFolderScope && recordCount <= 0) {
    showNoRecordsWarning();
    return;
  }

  const referenceCount = Number.parseInt(button.dataset.referenceCount || "0", 10);
  if (referenceCount > 0) {
    confirmBulkDelete(button, uids, true, isFolderScope);
    return;
  }

  deleteMissingFiles(button, uids, false, isFolderScope);
}

function confirmBulkDelete(button, uids, forceReferences, isFolderScope) {
  const modal = Modal.confirm(
    button.dataset.confirmTitle || t("faltools.js.confirmDeleteReferencedTitle", "Referenzierte Dateien löschen?"),
    button.dataset.confirmMessage || "",
    SeverityEnum.warning,
    [
      {
        text: button.dataset.confirmCancel || t("faltools.js.buttonCancel", "Abbrechen"),
        active: true,
        btnClass: "btn-default",
        name: "cancel",
      },
      {
        text: button.dataset.confirmDelete || t("faltools.js.buttonDelete", "Löschen"),
        btnClass: "btn-warning",
        name: "delete",
      },
    ],
  );

  modal.addEventListener("button.clicked", (modalEvent) => {
    if (modalEvent.target.name === "delete") {
      deleteMissingFiles(button, uids, forceReferences, isFolderScope);
    }
    modal.hideModal();
  });
}

function openRestoreDialog(button) {
  const fileInput = document.createElement("input");
  fileInput.type = "file";
  fileInput.style.display = "none";
  fileInput.addEventListener("change", async () => {
    const selectedFile = fileInput.files?.item(0) ?? null;
    if (selectedFile === null) {
      return;
    }
    await restoreMissingFile(button, selectedFile);
  });
  document.body.append(fileInput);
  fileInput.click();
  fileInput.remove();
}

function confirmReferencedDelete(button) {
  const modal = Modal.confirm(
    button.dataset.confirmTitle || t("faltools.js.confirmDeleteReferencedTitle", "Delete referenced missing file?"),
    button.dataset.confirmMessage || "",
    SeverityEnum.warning,
    [
      {
        text: button.dataset.confirmCancel || t("faltools.js.buttonCancel", "Cancel"),
        active: true,
        btnClass: "btn-default",
        name: "cancel",
      },
      {
        text: button.dataset.confirmDelete || t("faltools.js.buttonDelete", "Delete"),
        btnClass: "btn-warning",
        name: "delete",
      },
    ],
  );

  modal.addEventListener("button.clicked", (modalEvent) => {
    if (modalEvent.target.name === "delete") {
      deleteMissingFile(button, true);
    }
    modal.hideModal();
  });
}

async function deleteMissingFile(button, forceReferences) {
  button.disabled = true;

  try {
    const response = await new AjaxRequest(button.dataset.deleteUrl).post({
      uid: button.dataset.uid,
      forceReferences: forceReferences ? "1" : "0",
    });
    const result = await response.resolve();
    Notification.success(result.title || "", result.message || "");
    top.document.dispatchEvent(new CustomEvent("faltools:missing-files:changed"));
    top.TYPO3.Backend.ContentContainer.setUrl(button.dataset.returnUrl);
  } catch (error) {
    let result = null;
    if (error && typeof error.resolve === "function") {
      result = await error.resolve().catch(() => null);
    }
    Notification.error(
      result?.title || button.dataset.confirmTitle || t("faltools.js.errorTitle", "Error"),
      result?.message || t("faltools.js.deleteErrorMessage", "The missing file entry could not be deleted."),
    );
    button.disabled = false;
  }
}

async function restoreMissingFile(button, file) {
  button.disabled = true;

  const payload = new FormData();
  payload.append("uid", button.dataset.uid || "0");
  payload.append("uploadedFile", file);

  try {
    const response = await new AjaxRequest(button.dataset.restoreUrl).post(payload);
    const result = await response.resolve();
    Notification.success(result.title || "", result.message || "");
    top.document.dispatchEvent(new CustomEvent("faltools:missing-files:changed"));
    top.TYPO3.Backend.ContentContainer.setUrl(button.dataset.returnUrl);
  } catch (error) {
    let result = null;
    if (error && typeof error.resolve === "function") {
      result = await error.resolve().catch(() => null);
    }
    Notification.error(
      result?.title || t("faltools.js.errorTitle", "Error"),
      result?.message || t("faltools.js.restoreErrorMessage", "The missing file entry could not be restored."),
    );
    button.disabled = false;
  }
}

async function deleteMissingFiles(button, uids, forceReferences, isFolderScope) {
  button.disabled = true;

  // Folder scope is resolved server-side via storage+path to avoid trusting client-side UID lists.
  const payload = {
    forceReferences: forceReferences ? "1" : "0",
  };
  if (isFolderScope) {
    payload.scope = "folder";
    payload.storage = button.dataset.storage || "";
    payload.path = button.dataset.path || "";
  } else {
    payload.scope = "page";
    payload.uids = uids;
  }

  try {
    const response = await new AjaxRequest(button.dataset.deleteUrl).post(payload);
    const result = await response.resolve();
    if (result.success === false) {
      Notification.warning(result.title || "", result.message || "");
    } else {
      Notification.success(result.title || "", result.message || "");
    }
    top.document.dispatchEvent(new CustomEvent("faltools:missing-files:changed"));
    top.TYPO3.Backend.ContentContainer.setUrl(button.dataset.returnUrl);
  } catch (error) {
    let result = null;
    if (error && typeof error.resolve === "function") {
      result = await error.resolve().catch(() => null);
    }
    Notification.error(
      result?.title || t("faltools.js.errorTitle", "Error"),
      result?.message || t("faltools.js.bulkDeleteErrorMessage", "Die fehlenden Dateieinträge konnten nicht gelöscht werden."),
    );
    button.disabled = false;
  }
}

function parseUidList(value) {
  return value
    .split(",")
    .map((item) => Number.parseInt(item.trim(), 10))
    .filter((uid) => Number.isInteger(uid) && uid > 0);
}

function showNoRecordsWarning() {
  Notification.warning(
    t("faltools.js.noRecordsTitle", "Keine Einträge"),
    t("faltools.js.noRecordsMessage", "Für diese Aktion wurden keine fehlenden Dateien gefunden."),
  );
}
