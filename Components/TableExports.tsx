import React from "react";
import TableExtended, {
  TableExtendedProps,
  TableExtendedState,
} from "@hubleto/react-ui/ext/TableExtended";
import { FormExport, FormExportProps } from "./FormExport";

interface TableExportsProps extends TableExtendedProps {}
interface TableExportsState extends TableExtendedState {}

export default class TableExports extends TableExtended<
  TableExportsProps,
  TableExportsState
> {
  static defaultProps = {
    ...TableExtended.defaultProps,
    formUseModalSimple: true,
    model: "Hubleto/App/Enterprise/Einvoice/Models/Export",
  };

  translationContext: string = "Hubleto\\App\\Enterprise\\Einvoice\\Loader";
  translationContextInner: string = "Components\\TableExports";

  renderForm(): JSX.Element {
    let formProps = this.getFormProps() as FormExportProps;
    return <FormExport {...formProps} />;
  }

  renderActionsColumn(rowData: any, actions: any = []): JSX.Element {
    return (
      <div className="flex gap-1">
        {super.renderActionsColumn(rowData, actions)}
        <button
          className="btn btn-sm btn-light"
          onClick={(e) => {
            e.stopPropagation();
            globalThis.alert(rowData.xml_content);
          }}
          title="Preview XML"
        >
          <i className="fas fa-file-code"></i>
        </button>
        {/* testing only */}
        <a
          className="btn btn-sm btn-light"
          href={`einvoice/exports?action=download&id=${rowData.id}`}
          onClick={(e) => e.stopPropagation()}
          title="Download XML"
          target="_blank"
        >
          <i className="fas fa-download"></i>
        </a>
      </div>
    );
  }
}
