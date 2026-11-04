# Terraform outputs for Greenwood production topology

output "node_topology_json" {
  description = "JSON-formatted node topology for external tools"
  value       = jsonencode(local.nodes)
}

output "node_count" {
  description = "Total number of configured nodes"
  value       = length(local.nodes)
}

output "validator_node_count" {
  description = "Number of validator nodes (authoritative set is exactly A/B/C)"
  value       = length([for k, v in local.nodes : k if contains(try(v.roles, []), "validator")])
}

output "provider_node_count" {
  description = "Number of provider nodes (A/B/C on nodes 1/2/3)"
  value       = length([for k, v in local.nodes : k if contains(try(v.roles, []), "provider")])
}

output "controller_node_count" {
  description = "Number of controller/operations nodes (exactly node-1)"
  value       = length([for k, v in local.nodes : k if v.controller])
}

output "gateway_node_count" {
  description = "Number of gateway/router-only nodes (exactly node-4)"
  value       = length([for k, v in local.nodes : k if v.gateway])
}

output "all_node_addresses" {
  description = "List of all node IP addresses"
  value       = local.node_addresses
}

output "validator_addresses" {
  description = "List of validator node IP addresses (A/B/C)"
  value       = [for v in local.validators : v.address]
}

output "provider_addresses" {
  description = "List of provider node IP addresses (A/B/C on nodes 1/2/3)"
  value       = [for v in local.providers : v.address]
}

output "gateway_addresses" {
  description = "List of gateway node management addresses (node-4)"
  value       = [for g in local.gateways : g.address]
}

output "node_validator_mapping" {
  description = "Mapping of node hostname to validator label"
  value = {
    for v in local.validators :
    v.hostname => v.validator_label
  }
}

output "node_provider_mapping" {
  description = "Mapping of node hostname to provider label"
  value = {
    for v in local.providers :
    v.hostname => v.provider_label
  }
}

output "node_controller_mapping" {
  description = "Mapping of node hostname to controller designation (operations only)"
  value = {
    for c in local.controllers :
    c.hostname => true
  }
}

output "node_gateway_mapping" {
  description = "Mapping of node hostname to gateway designation (no Greenwood protocol role)"
  value = {
    for g in local.gateways :
    g.hostname => g.gateway_address
  }
}

output "topology_validation" {
  description = "Validation status of the topology (must be TOPOLOGY_OK)"
  value = {
    status            = local.topology_ok ? "TOPOLOGY_OK" : "TOPOLOGY_INVALID"
    failure           = local.topology_failure
    validator_set     = local.validator_set_ok ? "A,B,C" : "INVALID"
    provider_set      = local.provider_set_ok ? "node-1:A,node-2:B,node-3:C" : "INVALID"
    controller_set    = local.controller_ok ? "node-1" : "INVALID"
    gateway_set       = local.gateway_ok ? "node-4" : "INVALID"
    node4_has_no_role = local.node4_clean
  }
}

output "topology_failure" {
  description = "Empty when the topology is valid, otherwise a description of the violation"
  value       = local.topology_failure
}
