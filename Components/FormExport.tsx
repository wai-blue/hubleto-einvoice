import React from "react";
import FormExtended, {
  FormExtendedProps,
  FormExtendedState,
} from "@hubleto/react-ui/ext/FormExtended";

// for debugging purposes, delete later
export interface FormExportProps extends FormExtendedProps {}
export interface FormExportState extends FormExtendedState {
  debugRawData?: string;
  debugLoading?: boolean;
}

export class FormExport extends FormExtended<FormExportProps, FormExportState> {
  static defaultProps = {
    ...FormExtended.defaultProps,
    model: "Hubleto/App/Enterprise/Einvoice/Models/Export",
  };

  translationContext: string = "Hubleto\\App\\Enterprise\\Einvoice\\Loader";
  translationContextInner: string = "Components\\FormExport";

  // For debugging purposes, delete later
  componentDidMount() {
    if (super.componentDidMount) super.componentDidMount();
    this.loadDebugData();
  }

  componentDidUpdate(prevProps: FormExportProps, prevState: FormExportState) {
    if (super.componentDidUpdate)
      super.componentDidUpdate(prevProps, prevState);

    const prevInvoiceId = prevState?.record?.id_invoice;
    const currInvoiceId = this.state?.record?.id_invoice;
    if (currInvoiceId && currInvoiceId !== prevInvoiceId) {
      this.loadDebugData();
    }
  }
  // For debugging purposes, delete later
  loadDebugData() {
    const invoiceId = this.state?.record?.id_invoice;
    if (!invoiceId || invoiceId <= 0) return;

    this.setState({ debugLoading: true, debugRawData: undefined } as any);

    globalThis
      .fetch(`einvoice/api/debug-invoice-data/${invoiceId}`)
      .then((res: any) => res.json())
      .then((json: any) => {
        const rawData = json.rawData ?? json;
        this.setState({
          debugRawData: JSON.stringify(rawData, null, 2),
          debugLoading: false,
        } as any);
      })
      .catch((err: any) => {
        this.setState({
          debugRawData: "Error loading raw data: " + err.message,
          debugLoading: false,
        } as any);
      });
  }
  // For debugging purposes, delete later
  renderContent(): null | JSX.Element {
    const content = super.renderContent();
    return (
      <>
        <div
          style={{
            padding: "12px",
            borderRadius: "6px",
            marginBottom: "12px",
            maxHeight: "400px",
            overflow: "auto",
            fontSize: "12px",
            fontFamily: "monospace",
          }}
        >
          <div style={{ fontWeight: "bold", marginBottom: "8px" }}>
            DEBUG: Raw getPreviewVars() data (invoice #
            {this.state?.record?.id_invoice})
          </div>
          {(this.state as any).debugLoading ? (
            <div style={{ color: "#888" }}>Loading...</div>
          ) : (
            <pre
              style={{
                margin: 0,
                whiteSpace: "pre-wrap",
                wordBreak: "break-all",
              }}
            >
              {(this.state as any).debugRawData ?? "No data loaded yet"}
            </pre>
          )}
        </div>
        {content}
      </>
    );
  }
}
