/**
 * External dependencies
 */
import head from 'lodash/head';
import React, { Fragment, PureComponent } from 'react';
import { withRouter } from 'react-router'
import { Button, DropZoneProvider, DropZone } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { withDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import FileInput from './file-input';

const validFileTypes = Object.freeze( [ 'text/xml' ] );
const isValidFileType = type => validFileTypes.includes( type );

class FileSelection extends PureComponent {
	state = {
		hasDropped: false,
		isFetching: false,
	};

	handleFileSelection = async ( files = [] ) => {
		const { setUploadResult } = this.props;
		const file = head( files );
		const { type, size } = file;

		if ( ! size ) {
			console.error( 'Cannot upload an empty file' );
			return;
		}

		if ( ! isValidFileType( type ) ) {
			console.error( `File type ${ type } is not supported` );
			return;
		}

		this.setState( {
			hasDropped: true,
			isFetching: true,
		} );

		const body = new FormData();
		body.append( 'import', file );
		body.append( 'status', 'private' );

		apiFetch( {
			method: 'POST',
			path: '/wordpress-importer/v1/attachment',
			body,
		} )
			.then( response => {
				console.log( { response } );

				this.setState( { isFetching: false } );
				setUploadResult( response );
				this.props.history.push( '/map' );
			} )
			.catch( error => {
				this.setState( { isFetching: false } );
				console.error( { error } )
			} );
	};

	render() {
		const { setState, state } = this;
		const { hasDropped, isFetching } = state;

		return (
			<Fragment>
				<DropZoneProvider>
					<h2>Import WordPress</h2>
					<div>Howdy! Upload your WordPress eXtended RSS (WXT) file and we'll import the posts, pages, comments, custom fields, categories, and tags into this site.</div>
					<div>Choose a WXR (.xml) file to upload, or drop a file here, and your import will begin</div>
					<div className="wordpress-importer__div-actions">
						{ isFetching
							? ( <span>Loading…</span> )
							: ( <FileInput onFileSelected={ this.handleFileSelection }>Choose file</FileInput> )
						}
					</div>
					<div>
						<DropZone
							onFilesDrop={ this.handleFileSelection }
							onHTMLDrop={ this.handleFileSelection }
							onDrop={ this.handleFileSelection }
						/>
					</div>
				</DropZoneProvider>
			</Fragment>
		);
	}
}

export default withDispatch( ( dispatch ) => {
	return {
		setUploadResult: dispatch( 'wordpress-importer' ).setUploadResult,
	};
} )( withRouter( FileSelection ) );
