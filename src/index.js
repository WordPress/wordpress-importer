import ReactDOM from "react-dom";
import React from "react";
import { DropZoneProvider, DropZone } from '@wordpress/components';
import { withState } from '@wordpress/compose';

const MyDropZone = withState( {
	hasDropped: false,
} )( ( { hasDropped, setState } ) => (
	<DropZoneProvider>
		<div>
			{ hasDropped ? 'Dropped!' : 'Drop something here' }
			<DropZone 
				onFilesDrop={ () => setState( { hasDropped: true } ) }
				onHTMLDrop={ () => setState( { hasDropped: true } )  }
				onDrop={ () => setState( { hasDropped: true } ) } 
			/>
		</div>
	</DropZoneProvider>
) );

ReactDOM.render( <MyDropZone />, document.getElementById( 'wordpress-importer-root' ) );
