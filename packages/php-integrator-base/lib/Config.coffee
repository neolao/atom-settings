fs = require 'fs'

module.exports =

##*
# Abstract base class for managing configurations.
##
class Config
    ###*
     * Raw configuration object.
    ###
    data: null

    ###*
     * Array of change listeners.
    ###
    listeners: null

    ###*
     * Constructor.
    ###
    constructor: () ->
        @listeners = {}

        @data =
            phpCommand                     : null
            packagePath                    : null

            # See also https://secure.php.net/urlhowto.php .
            php_documentation_base_url     : {
                functions: 'https://secure.php.net/function.'
            }

        @load()

    ###*
     * Loads the configuration.
    ###
    load: () ->
        throw new Error("This method is abstract and must be implemented!")

    ###*
     * Registers a listener that is invoked when the specified property is changed.
    ###
    onDidChange: (name, callback) ->
        if name not of @listeners
            @listeners[name] = []

        @listeners[name].push(callback)

    ###*
     * Retrieves the config setting with the specified name.
     *
     * @return {mixed}
    ###
    get: (name) ->
        return @data[name]

    ###*
     * Retrieves the config setting with the specified name.
     *
     * @param {string} name
     * @param {mixed}  value
    ###
    set: (name, value) ->
        @data[name] = value

        if name of @listeners
            for listener in @listeners[name]
                listener(value, name)
