import { html, LitElement, nothing } from "lit";
import "@typo3/backend/element/icon-element.js";

export const navigationComponentName = "faltools-missing-files-tree";

function t(key, fallback) {
  return top.TYPO3?.lang?.[key] || fallback;
}

class MissingFilesTree extends LitElement {
  static properties = {
    nodes: { state: true },
    storageOptions: { state: true },
    selectedStorage: { state: true },
    moduleUrl: { state: true },
    labels: { state: true },
    loading: { state: true },
    error: { state: true },
    activeUrl: { state: true },
  };

  constructor() {
    super();
    this.nodes = [];
    this.storageOptions = {};
    this.selectedStorage = null;
    this.moduleUrl = "";
    this.labels = {};
    this.loading = true;
    this.error = "";
    this.activeUrl = "";
    this.reloadTree = () => this.loadTree();
  }

  createRenderRoot() {
    return this;
  }

  connectedCallback() {
    super.connectedCallback();
    document.addEventListener("faltools:missing-files:changed", this.reloadTree);
    this.loadTree();
  }

  disconnectedCallback() {
    document.removeEventListener("faltools:missing-files:changed", this.reloadTree);
    super.disconnectedCallback();
  }

  async loadTree() {
    this.loading = true;
    this.error = "";

    try {
      const response = await fetch(this.buildTreeRequestUrl(), {
        credentials: "same-origin",
      });
      if (!response.ok) {
        throw new Error(`${this.translate("treeRequestFailed", "Tree request failed")} (${response.status})`);
      }
      this.applyTreeResponse(await response.json());
    } catch (error) {
      this.error = error instanceof Error ? error.message : this.translate("treeRequestFailed", "Tree request failed");
    } finally {
      this.loading = false;
    }
  }

  selectNode(event, node) {
    event.preventDefault();
    this.activeUrl = node.url;
    top.TYPO3.Backend.ContentContainer.setUrl(node.url);
  }

  getCurrentContentParams() {
    const currentUrl = top.TYPO3.Backend.ContentContainer?.url || "";
    const url = new URL(currentUrl, top.location.origin);
    return new URLSearchParams(url.search);
  }

  buildTreeRequestUrl() {
    const selectedStorage = this.getCurrentContentParams().get("storage");
    const treeUrl = new URL(top.TYPO3.settings.ajaxUrls.faltools_missing_files_tree, top.location.origin);
    if (selectedStorage !== null && selectedStorage !== "") {
      treeUrl.searchParams.set("storage", selectedStorage);
    }
    return treeUrl.toString();
  }

  applyTreeResponse(data) {
    this.nodes = data.nodes ?? [];
    this.storageOptions = data.storageOptions ?? {};
    this.selectedStorage = data.selectedStorage ?? null;
    this.moduleUrl = data.moduleUrl ?? "";
    this.labels = data.labels ?? {};
  }

  handleStorageChange(event) {
    const storageValue = event.target.value;
    // Reset to page 1 and clear folder filter whenever storage scope changes.
    const targetUrl = new URL(this.moduleUrl || top.TYPO3.Backend.ContentContainer?.url || "", top.location.origin);
    targetUrl.searchParams.set("page", "1");
    targetUrl.searchParams.delete("path");
    if (storageValue === "") {
      targetUrl.searchParams.delete("storage");
    } else {
      targetUrl.searchParams.set("storage", storageValue);
    }
    top.TYPO3.Backend.ContentContainer.setUrl(targetUrl.toString());
  }

  render() {
    const storageEntries = Object.entries(this.storageOptions);
    const showStorageFilter = storageEntries.length > 1;

    return html`
      <style>
        faltools-missing-files-tree.scaffold-content-navigation-component {
          display: flex;
          flex: 1 1 0;
          flex-direction: column;
          min-height: 0;
        }

        .faltools-missing-tree {
          gap: .5rem;
          padding: var(--typo3-spacing);
          padding-block-start: calc(var(--typo3-spacing) + 2rem);
          overflow: hidden;
        }

        .faltools-missing-tree__storage {
          display: grid;
          gap: .25rem;
          flex: 0 0 auto;
        }

        .faltools-missing-tree__storage-label {
          font-size: .75rem;
          color: var(--typo3-text-color-secondary, #6c757d);
        }

        .faltools-missing-tree__toolbar {
          display: flex;
          justify-content: flex-start;
          flex: 0 0 auto;
        }

        .faltools-missing-tree__body {
          flex: 1 1 0;
          min-height: 0;
          overflow: auto;
        }

        .faltools-missing-tree__list {
          list-style: none;
          margin: 0;
          padding: 0;
        }

        .faltools-missing-tree__list .faltools-missing-tree__list {
          padding-left: 1rem;
        }

        .faltools-missing-tree__link {
          display: flex;
          align-items: center;
          gap: .35rem;
          min-height: 1.75rem;
          padding: .125rem .25rem;
          color: inherit;
          text-decoration: none;
          border-radius: var(--typo3-component-border-radius);
        }

        .faltools-missing-tree__link:hover,
        .faltools-missing-tree__link.is-active {
          color: inherit;
          text-decoration: none;
          background: var(--typo3-list-item-hover-bg, #f2f2f2);
        }

        .faltools-missing-tree__link.is-active {
          font-weight: 700;
        }

        .faltools-missing-tree__label {
          flex: 1 1 auto;
          min-width: 0;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .faltools-missing-tree__badge {
          flex: 0 0 auto;
          min-width: 1.5rem;
          padding: 0 .35rem;
          border-radius: 1rem;
          text-align: center;
          font-size: .75rem;
          line-height: 1.25rem;
          color: var(--typo3-badge-color, #fff);
          background: var(--typo3-badge-bg, #6c757d);
        }

        .faltools-missing-tree__badge--references {
          background: var(--typo3-state-warning-bg, #f0ad4e);
        }

        .faltools-missing-tree__message {
          padding: .5rem;
          color: var(--typo3-text-color-secondary, #6c757d);
        }
      </style>
      <div class="tree faltools-missing-tree">
        ${showStorageFilter
          ? html`
              <div class="faltools-missing-tree__storage">
                <label class="faltools-missing-tree__storage-label" for="faltools-missing-tree-storage">${this.translate("treeStorageLabel", "Storage")}</label>
                <select
                  id="faltools-missing-tree-storage"
                  class="form-select form-select-sm"
                  .value=${this.selectedStorage !== null ? String(this.selectedStorage) : ""}
                  @change=${this.handleStorageChange}
                >
                  <option value="">${this.translate("treeAllStorages", "All storages")}</option>
                  ${storageEntries.map(
                    ([uid, name]) => html`<option value=${uid}>${name} [${uid}]</option>`,
                  )}
                </select>
              </div>
            `
          : nothing}
        <header class="faltools-missing-tree__toolbar">
          <button type="button" class="btn btn-default btn-sm" title=${this.translate("treeRefreshLabel", "Refresh")} @click=${() => this.loadTree()}>
            <typo3-backend-icon identifier="actions-refresh" size="small"></typo3-backend-icon>
          </button>
        </header>
        <div class="navigation-tree-container faltools-missing-tree__body">
          ${this.renderBody()}
        </div>
      </div>
    `;
  }

  renderBody() {
    if (this.loading) {
      return html`<div class="faltools-missing-tree__message">${this.translate("treeLoading", "Loading...")}</div>`;
    }
    if (this.error !== "") {
      return html`<div class="faltools-missing-tree__message">${this.error}</div>`;
    }
    if (this.nodes.length === 0) {
      return html`<div class="faltools-missing-tree__message">${this.translate("treeNoMissingFiles", "No missing files")}</div>`;
    }

    return this.renderNodes(this.nodes);
  }

  renderNodes(nodes) {
    return html`
      <ul class="faltools-missing-tree__list">
        ${nodes.map((node) => this.renderNode(node))}
      </ul>
    `;
  }

  renderNode(node) {
    const isActive = node.url === this.activeUrl;
    const missingTitle = `${this.translate("treeBadgeMissing", "Fehlende Dateien im Ordner (rekursiv)")}: ${node.missingFiles}`;
    const referencesTitle = `${this.translate("treeBadgeReferences", "Davon mit Referenzen")}: ${node.referencedFiles}`;

    return html`
      <li class="faltools-missing-tree__item">
        <a
          href=${node.url}
          class="faltools-missing-tree__link ${isActive ? "is-active" : ""}"
          title=${node.identifier}
          @click=${(event) => this.selectNode(event, node)}
        >
          <typo3-backend-icon identifier=${node.level === 0 ? "apps-filetree-root" : "apps-filetree-folder-default"} size="small"></typo3-backend-icon>
          <span class="faltools-missing-tree__label">${node.label}</span>
          <span class="faltools-missing-tree__badge" title=${missingTitle}>${node.missingFiles}</span>
          ${node.hasReferences
            ? html`<span class="faltools-missing-tree__badge faltools-missing-tree__badge--references" title=${referencesTitle}>${node.referencedFiles}</span>`
            : nothing}
        </a>
        ${node.children?.length ? this.renderNodes(node.children) : nothing}
      </li>
    `;
  }

  translate(labelKey, fallback) {
    return this.labels?.[labelKey] || t(`faltools.js.${labelKey}`, fallback);
  }
}

customElements.define(navigationComponentName, MissingFilesTree);
